<?php

namespace App\Console\Commands;

use App\Models\DailyCheck;
use App\Models\FeedPurchase;
use App\Models\Stock;
use App\Services\UnitConverter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RECALCUL DU COÛT DE L'ALIMENT — historique des achats en sacs.
 *
 * ─── POURQUOI ───
 *
 * `CreateFeedPurchase` calculait le coût au kilo d'un achat en sacs avec 50 kg
 * CODÉ EN DUR, en ignorant le réglage `general.feed_bag_weight`. Une exploitation
 * achetant en sacs de 25 kg voyait donc son coût au kilo DIVISÉ PAR DEUX.
 *
 * Ce coût ne dort pas : il alimente le CMP de l'article, et le CMP est FIGÉ dans
 * `daily_checks.feed_unit_cost` à chaque pointage. C'est cette valeur figée qui
 * construit le coût de revient des bandes. Corriger le CMP courant ne répare donc
 * rien du passé — il faut revaloriser les consommations une par une.
 *
 * ─── COMMENT ───
 *
 * Par REJEU CHRONOLOGIQUE, pas par règle de trois. Une simple multiplication par
 * un facteur serait fausse dès qu'un article mêle des achats en sacs et en kilos,
 * ou dès que le poids du sac a changé en cours de route.
 *
 * On rejoue, article par article et dans l'ordre des dates :
 *
 *   ENTRÉE   (achat)       → quantité et valeur s'ajoutent ; le CMP se recalcule.
 *   SORTIE   (consommation) → sort au CMP du moment ; le CMP ne bouge pas, mais
 *                             le poids des achats suivants, oui.
 *
 * C'est la définition du coût moyen pondéré. L'ordre importe : sans les sorties,
 * un achat tardif de 10 kg pèserait autant qu'un stock déjà consommé.
 *
 * ─── CE QUE LA COMMANDE NE FAIT PAS ───
 *
 * Elle REFUSE de toucher un article dont le stock reçoit des entrées qu'elle ne
 * sait pas valoriser (production de la provenderie, ajustements d'inventaire) :
 * elle le signale pour examen plutôt que de le revaloriser à moitié. Une
 * revalorisation partielle serait pire que l'écart qu'on corrige.
 *
 * ─── SÉCURITÉ ───
 *
 * SIMULATION PAR DÉFAUT. Rien n'est écrit sans `--force`. Le rapport montre chaque
 * valeur avant/après et l'impact total par bande. Le recalcul est IDEMPOTENT : il
 * dérive des achats, jamais de la valeur courante — le relancer deux fois donne le
 * même résultat.
 */
class RecomputeFeedCosts extends Command
{
    protected $signature = 'feed:recompute-costs
        {--bag-weight= : Poids de sac à appliquer aux achats SANS poids propre (défaut : le réglage général)}
        {--from= : Ne rejouer que les achats à partir de cette date (AAAA-MM-JJ)}
        {--article=* : Limiter à ces articles (nom exact ; répétable)}
        {--force : ÉCRIRE les corrections. Sans ce drapeau, simulation seule}';

    protected $description = "Revalorise le coût de l'aliment (CMP et consommations figées) par rejeu des achats";

    public function handle(): int
    {
        $write = (bool) $this->option('force');
        $bagWeightOption = $this->option('bag-weight') !== null ? (float) $this->option('bag-weight') : null;
        $from = $this->option('from');
        $onlyArticles = (array) $this->option('article');

        $this->line('');
        $this->line($write
            ? '<fg=red;options=bold>MODE ÉCRITURE — les corrections seront enregistrées.</>'
            : '<fg=yellow;options=bold>SIMULATION — aucune écriture. Ajouter --force pour appliquer.</>');
        $this->line('');

        $articles = FeedPurchase::query()
            ->when($from, fn ($q) => $q->where('purchase_date', '>=', $from))
            ->when($onlyArticles, fn ($q) => $q->whereIn('feed_type', $onlyArticles))
            ->distinct()
            ->pluck('feed_type')
            ->filter()
            ->values();

        if ($articles->isEmpty()) {
            $this->info('Aucun achat aliment à rejouer.');

            return self::SUCCESS;
        }

        $skipped = [];
        $totalChecksChanged = 0;
        $totalImpact = 0.0;

        foreach ($articles as $article) {
            $report = $this->replayArticle($article, $bagWeightOption, $from);

            if ($report['skipped'] !== null) {
                $skipped[$article] = $report['skipped'];
                continue;
            }

            $this->renderArticle($article, $report);

            $totalChecksChanged += count($report['checks']);
            $totalImpact += $report['impact'];

            if ($write) {
                $this->apply($article, $report);
            }
        }

        $this->renderSummary($skipped, $totalChecksChanged, $totalImpact, $write);

        return self::SUCCESS;
    }

    /**
     * Rejoue un article et renvoie ce qu'il faudrait corriger.
     *
     * @return array{skipped: ?string, cmp_before: float, cmp_after: float, checks: array<int, array{id:int, date:string, before:float, after:float, qty:float}>, impact: float}
     */
    private function replayArticle(string $article, ?float $bagWeightOption, ?string $from): array
    {
        $stock = Stock::withoutGlobalScopes()
            ->where('item_name', $article)
            ->where('category', Stock::CAT_CONSO)
            ->first();

        if (! $stock) {
            return $this->skip("aucun article de stock nommé « {$article} »");
        }

        // Entrées que cette commande ne sait pas valoriser : production de la
        // provenderie (créditée sous le nom de la FORMULE) ou ajustement manuel.
        // On refuse plutôt que de revaloriser une partie du stock seulement.
        $foreignInflows = DB::table('stock_movements')
            ->where('stock_id', $stock->id)
            ->where('type', 'in')
            ->where(function ($q) {
                $q->where('notes', 'like', '%Provenderie%')
                  ->orWhere('notes', 'like', '%justement%');
            })
            ->count();

        if ($foreignInflows > 0) {
            return $this->skip("{$foreignInflows} entrée(s) hors achat (provenderie ou ajustement) : à revaloriser à la main");
        }

        $purchases = FeedPurchase::withoutGlobalScopes()
            ->where('feed_type', $article)
            ->when($from, fn ($q) => $q->where('purchase_date', '>=', $from))
            ->orderBy('purchase_date')->orderBy('id')
            ->get(['id', 'purchase_date', 'quantity', 'unit', 'total_price', 'metadata']);

        if ($purchases->isEmpty()) {
            return $this->skip('aucun achat sur la période');
        }

        $checks = DailyCheck::withoutGlobalScopes()
            ->where('feed_type', $article)
            ->where('feed_consumed', '>', 0)
            ->when($from, fn ($q) => $q->where('check_date', '>=', $from))
            ->orderBy('check_date')->orderBy('id')
            ->get(['id', 'check_date', 'feed_consumed', 'feed_unit_cost']);

        // Événements triés par date. Un achat du jour précède la consommation du
        // même jour : l'aliment arrive, puis il est distribué.
        $events = collect();

        foreach ($purchases as $p) {
            $bagWeight = UnitConverter::bagWeight(
                $p->metadata['bag_weight'] ?? $bagWeightOption
            );

            $events->push([
                'date'  => $p->purchase_date->toDateString(),
                'order' => 0,
                'kind'  => 'in',
                'kg'    => UnitConverter::isSac($p->unit)
                    ? (float) $p->quantity * $bagWeight
                    : (float) $p->quantity,
                'money' => (float) $p->total_price,
            ]);
        }

        foreach ($checks as $c) {
            $events->push([
                'date'  => $c->check_date->toDateString(),
                'order' => 1,
                'kind'  => 'out',
                'kg'    => (float) $c->feed_consumed,
                'row'   => $c,
            ]);
        }

        $qty = 0.0;
        $value = 0.0;
        $corrections = [];
        $impact = 0.0;

        foreach ($events->sortBy([['date', 'asc'], ['order', 'asc']]) as $event) {
            if ($event['kind'] === 'in') {
                $qty += $event['kg'];
                $value += $event['money'];
                continue;
            }

            $cmp = $qty > 0 ? $value / $qty : 0.0;
            $consumed = min($event['kg'], $qty);

            $qty -= $consumed;
            $value -= $consumed * $cmp;

            $row = $event['row'];
            $before = (float) ($row->feed_unit_cost ?? 0);
            $after = round($cmp, 2);

            // On ne touche que ce qui change réellement : un rapport qui liste des
            // lignes identiques cache celles qui comptent.
            if (abs($after - $before) >= 0.01) {
                $corrections[] = [
                    'id'     => $row->id,
                    'date'   => $row->check_date->toDateString(),
                    'before' => $before,
                    'after'  => $after,
                    'qty'    => (float) $row->feed_consumed,
                ];

                $impact += ($after - $before) * (float) $row->feed_consumed;
            }
        }

        return [
            'skipped'    => null,
            'cmp_before' => (float) $stock->unit_price,
            'cmp_after'  => $qty > 0 ? round($value / $qty, 2) : (float) $stock->unit_price,
            'stock_id'   => $stock->id,
            'checks'     => $corrections,
            'impact'     => $impact,
        ];
    }

    /** @return array{skipped: string, cmp_before: float, cmp_after: float, checks: array, impact: float} */
    private function skip(string $reason): array
    {
        return ['skipped' => $reason, 'cmp_before' => 0.0, 'cmp_after' => 0.0, 'checks' => [], 'impact' => 0.0];
    }

    private function renderArticle(string $article, array $report): void
    {
        $cmpMoved = abs($report['cmp_after'] - $report['cmp_before']) >= 0.01;

        if (! $cmpMoved && $report['checks'] === []) {
            $this->line("  <fg=gray>{$article} — rien à corriger.</>");

            return;
        }

        $this->line("<options=bold>{$article}</>");

        if ($cmpMoved) {
            $this->line(sprintf(
                '  Coût moyen pondéré : %s  →  %s GNF/kg',
                number_format($report['cmp_before'], 2, ',', ' '),
                number_format($report['cmp_after'], 2, ',', ' ')
            ));
        }

        if ($report['checks'] !== []) {
            $this->line(sprintf('  %d consommation(s) à revaloriser :', count($report['checks'])));

            foreach (array_slice($report['checks'], 0, 10) as $c) {
                $this->line(sprintf(
                    '    %s  %8.2f kg   %s → %s GNF/kg',
                    $c['date'], $c['qty'],
                    number_format($c['before'], 2, ',', ' '),
                    number_format($c['after'], 2, ',', ' ')
                ));
            }

            if (count($report['checks']) > 10) {
                $this->line(sprintf('    … et %d autre(s).', count($report['checks']) - 10));
            }

            $this->line(sprintf(
                '  Impact sur le coût de revient : %s GNF',
                number_format($report['impact'], 0, ',', ' ')
            ));
        }

        $this->line('');
    }

    private function apply(string $article, array $report): void
    {
        DB::transaction(function () use ($article, $report) {
            Stock::withoutGlobalScopes()->where('id', $report['stock_id'])
                ->update(['unit_price' => $report['cmp_after']]);

            foreach ($report['checks'] as $c) {
                DailyCheck::withoutGlobalScopes()->where('id', $c['id'])
                    ->update(['feed_unit_cost' => $c['after']]);
            }
        });

        // Trace durable : une correction de chiffres comptables doit rester
        // explicable des mois plus tard, ligne par ligne.
        Log::info(sprintf(
            '[feed:recompute-costs] %s — CMP %.2f → %.2f, %d consommation(s) revalorisée(s), impact %.0f GNF. Lignes : %s',
            $article,
            $report['cmp_before'], $report['cmp_after'],
            count($report['checks']), $report['impact'],
            implode(',', array_column($report['checks'], 'id'))
        ));
    }

    private function renderSummary(array $skipped, int $checks, float $impact, bool $write): void
    {
        $this->line('─────────────────────────────────────────────');

        if ($skipped !== []) {
            $this->warn('Articles ÉCARTÉS (à examiner à la main) :');
            foreach ($skipped as $article => $reason) {
                $this->line("  • {$article} : {$reason}");
            }
            $this->line('');
        }

        $this->line(sprintf(
            '%d consommation(s) %s. Impact total sur le coût de revient : %s GNF.',
            $checks,
            $write ? 'revalorisée(s)' : 'à revaloriser',
            number_format($impact, 0, ',', ' ')
        ));

        if (! $write) {
            $this->line('');
            $this->line('<options=bold>Rien n\'a été écrit.</> Relancer avec --force pour appliquer.');
        }

        $this->line('');
        $this->line('<fg=gray>Ce recalcul ne remonte pas dans les comptes de résultat déjà imprimés : il');
        $this->line('corrige les données dont ils sont tirés. Les rapports régénérés après coup');
        $this->line('afficheront les valeurs corrigées.</>');
    }
}
