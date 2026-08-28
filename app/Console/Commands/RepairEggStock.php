<?php

namespace App\Console\Commands;

use App\Models\EggProduction;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\StockIntegrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Répare le magasin d'œufs après les tris qui n'y sont jamais entrés.
 *
 * Tant qu'aucun article « XL / L / M / S » n'existait en catégorie « oeufs »,
 * `StockIntegrationService::syncMovement()` rendait `false` sans que personne ne
 * le regarde : le tri s'enregistrait sur la collecte, `is_graded` passait à true,
 * et pas un œuf n'entrait au magasin. Rien dans l'application ne créait ces
 * articles — donc, sur une base neuve, aucun tri n'a jamais abouti.
 *
 * La correction (GradeEggProduction) répare les tris À VENIR. Les tris DÉJÀ
 * faits, eux, restent invisibles : leurs calibres sont bien écrits sur la
 * collecte — la donnée n'est pas perdue — mais le stock ne les a jamais vus.
 * Cette commande reporte ce qui manque.
 *
 * ─── ELLE EST IDEMPOTENTE, ET C'EST L'ESSENTIEL ───
 *
 * Elle ne rejoue pas les tris : elle compare, pour chaque calibre, ce que les
 * collectes triées TOTALISENT à ce que les mouvements de stock ont DÉJÀ porté,
 * et n'écrit que l'écart. La relancer deux fois n'ajoute donc rien la seconde
 * fois, et une exploitation dont une partie des mouvements a abouti n'est pas
 * double-comptée.
 *
 * ─── SITE PAR SITE ───
 *
 * `FarmScope` ne s'applique QUE si `session('current_farm_id')` est défini. En
 * console il ne l'est pas : sans le poser, cette commande agrégeait les tris des
 * QUATRE sites en un seul chiffre, les comparait aux entrées d'un seul article
 * de stock — le premier trouvé — et écrivait le manque sur la ferme par défaut.
 *
 * Les œufs triés à Kindia et à Kérouané se seraient donc empilés sur le magasin
 * d'un seul site, les autres restant vides. Sur une exploitation mono-site le
 * résultat était juste, ce qui rendait le défaut invisible là où on le teste.
 *
 * On boucle donc sur les sites ACTIFS en posant la session, comme le font déjà
 * `stocks:sync` et `maintenance:check` — dont le commentaire dit la même chose :
 * « le scope ferme se règle sur la session, y compris en console : sans cela,
 * chaque site verrait les lots de tous les autres ».
 *
 * Usage :
 *   php artisan eggs:repair-stock            # SIMULATION — rien n'est écrit
 *   php artisan eggs:repair-stock --force    # applique les écarts
 *   php artisan eggs:repair-stock --farm=3   # un seul site
 *
 * Convention partagée avec batches:rebuild-quantities, feed:recompute-costs et
 * stocks:sync : une commande qui réécrit des chiffres simule par défaut.
 */
class RepairEggStock extends Command
{
    protected $signature = 'eggs:repair-stock
                            {--force : APPLIQUER les écarts. Sans ce drapeau : simulation seule}
                            {--farm= : Limiter à un site (défaut : tous les sites actifs)}';

    protected $description = 'Reporte au magasin les tris d\'œufs qui n\'y sont jamais entrés';

    public function handle(): int
    {
        $simulation = ! $this->option('force');

        if ($simulation) {
            $this->warn('SIMULATION — aucune écriture. Ajoutez --force pour appliquer.');
        }

        $sites = $this->option('farm')
            ? \App\Models\Farm::whereKey((int) $this->option('farm'))->get()
            : \App\Models\Farm::active()->get();

        if ($sites->isEmpty()) {
            $this->error('Aucun site actif.');
            return self::FAILURE;
        }

        $code = self::SUCCESS;

        foreach ($sites as $site) {
            $this->newLine();
            $this->line("<options=bold>{$site->name}</>");

            // Le scope ferme se règle sur la session, y compris en console : sans
            // cela, les tris de tous les sites seraient additionnés et reportés
            // sur le magasin d'un seul.
            session(['current_farm_id' => $site->id]);

            if ($this->reparerUnSite($simulation) === self::FAILURE) {
                $code = self::FAILURE;
            }
        }

        return $code;
    }

    /** La reprise pour le site courant (session déjà posée). */
    private function reparerUnSite(bool $simulation): int
    {
        $ecarts = $this->ecartsParArticle();

        if ($ecarts->isEmpty()) {
            $this->info('Le magasin d\'œufs est à jour : aucun écart.');
            return self::SUCCESS;
        }

        $this->table(
            ['Article', 'Trié (alvéoles)', 'Déjà en stock', 'À reporter'],
            $ecarts->map(fn ($e, $nom) => [
                $nom,
                number_format($e['attendu'], 2),
                number_format($e['porte'], 2),
                number_format($e['ecart'], 2),
            ])->values()->all()
        );

        if ($simulation) {
            $this->newLine();
            $this->line('Relancez avec --force pour porter ces quantités au magasin.');
            return self::SUCCESS;
        }

        /*
         * On ne REPREND que ce qui manque. Un écart négatif — plus d'entrées que
         * de tris — vient d'une saisie manuelle au magasin, pas d'un tri à
         * défaire : sortir des œufs sur cette base détruirait du stock réel. On
         * le signale, on n'y touche pas.
         */
        $aReporter = $ecarts->filter(fn ($e) => $e['ecart'] > 0);
        $enTrop    = $ecarts->filter(fn ($e) => $e['ecart'] < 0);

        DB::transaction(function () use ($aReporter) {
            foreach ($aReporter as $nom => $e) {
                Stock::firstOrCreate(
                    ['item_name' => $nom, 'category' => Stock::CAT_OEUFS],
                    ['unit' => 'Alvéole', 'current_quantity' => 0, 'alert_threshold' => 0],
                );

                StockIntegrationService::syncMovement(
                    $nom,
                    Stock::CAT_OEUFS,
                    $e['ecart'],
                    'in',
                    'Reprise : tri enregistré sans mouvement de stock',
                    'Alvéole',
                );
            }
        });

        $this->newLine();
        $this->info("{$aReporter->count()} article(s) mis à jour.");

        foreach ($enTrop as $nom => $e) {
            $this->warn(sprintf(
                "« %s » : le magasin a reçu %s alvéoles de plus que les tris n'en comptent. "
                . "Saisie manuelle probable — rien n'a été retiré.",
                $nom,
                number_format(abs($e['ecart']), 2),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Écart, par article, entre le total trié et ce que le stock a déjà reçu.
     *
     * Le total trié se lit sur les collectes (`is_graded`), qui sont la source
     * de vérité du calibrage — c'est ce que le technicien a compté. Ce que le
     * stock a reçu se lit sur les mouvements, entrées moins sorties, pour ne pas
     * confondre « jamais entré » avec « entré puis vendu ».
     */
    private function ecartsParArticle(): \Illuminate\Support\Collection
    {
        $attendu = [];

        foreach (EggProduction::gradeCodes() as $code) {
            $attendu[$code] = (float) EggProduction::where('is_graded', true)
                ->sum('grade_' . strtolower($code));
        }

        $parTray = (float) \App\Services\UnitConverter::eggsPerTray();

        $attendu['Cassé']    = (float) EggProduction::where('is_graded', true)->sum('broken_eggs') / $parTray;
        $attendu['Anomalie'] = (float) EggProduction::where('is_graded', true)->sum('small_eggs') / $parTray;

        return collect($attendu)
            ->map(function (float $qte, string $nom) {
                $porte = $this->dejaPorte($nom);

                return [
                    'attendu' => $qte,
                    'porte'   => $porte,
                    'ecart'   => round($qte - $porte, 4),
                ];
            })
            ->filter(fn ($e) => abs($e['ecart']) > 0.0001);
    }

    /**
     * Quantité déjà ENTRÉE au stock pour cet article — les entrées seules.
     *
     * Surtout pas le stock net, ni entrées moins sorties : une vente est une
     * sortie légitime, et la compter ferait ressembler des œufs VENDUS à des
     * œufs jamais entrés. La reprise les aurait recréés — elle aurait fabriqué
     * du stock à chaque passage, sur une exploitation qui vend.
     *
     * On compare donc un cumulé d'entrées à un cumulé de tris : deux grandeurs
     * de même nature, insensibles à ce qui est sorti depuis.
     */
    private function dejaPorte(string $itemName): float
    {
        $stock = Stock::where('item_name', $itemName)
            ->where('category', Stock::CAT_OEUFS)
            ->first();

        if (! $stock) {
            return 0.0;
        }

        return (float) StockMovement::where('stock_id', $stock->id)
            ->where('type', 'in')
            ->sum('quantity');
    }
}
