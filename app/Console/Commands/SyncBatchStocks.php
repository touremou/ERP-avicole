<?php

namespace App\Console\Commands;

use App\Actions\Stock\CompareEggStockToLedger;
use App\Models\Farm;
use App\Services\BatchQuantityService;
use Illuminate\Console\Command;

/**
 * RÉCONCILIATION DES EFFECTIFS, ET CONSTAT SUR LES ŒUFS. Rien n'est écrit sans --force.
 *
 * ─── CE QUE CETTE COMMANDE FAISAIT, ET POURQUOI ELLE NUISAIT ───
 *
 * Elle était planifiée CHAQUE NUIT (`Schedule::command('stocks:sync')->daily()`) et
 * sa partie « œufs » écrasait `stocks.current_quantity` avec la somme de toute la
 * production jamais enregistrée. C'est la même colonne que la vente décrémente :
 * chaque nuit, les œufs vendus revenaient donc en stock. Mesuré sur un cas monté à
 * la main : 100 alvéoles produites, 30 vendues, 70 restantes ; au matin, 100.
 *
 * Deux aggravations propres à une exploitation multi-sites :
 *
 *   • aucune portée par site. Le scope ferme ne s'applique que si une ferme
 *     courante est en session (cf. BelongsToFarm) et une commande artisan n'en a
 *     pas : la somme additionnait Kindia ET Kérouané, puis versait le total au
 *     PREMIER article trouvé — le second n'étant jamais touché ;
 *   • le compte rendu annonçait « ✅ Synchronisation terminée avec succès » après
 *     avoir écrit « Stock 'M' introuvable » cinq fois. Un succès proclamé sur cinq
 *     articles manquants sur six est le pire des comptes rendus.
 *
 * ─── CE QU'ELLE FAIT MAINTENANT ───
 *
 * Les EFFECTIFS sont délégués à BatchQuantityService, déjà la déclaration unique de
 * cette règle. La version précédente reportait la même formule pour son compte, avec
 * une différence lourde : elle écrivait par `$batch->update()`, ce qui déclenche le
 * BatchObserver — donc l'alerte de mortalité cumulée, à minuit, pour une simple
 * réconciliation. Le service écrit délibérément en direct pour l'éviter.
 *
 * Les ŒUFS ne sont plus recalculés : on CONSTATE l'écart entre le niveau et le
 * registre des mouvements. Voir CompareEggStockToLedger pour la raison — un
 * ajustement d'inventaire est enregistré sans signe, le registre ne permet donc pas
 * de trancher. L'outil correct existe déjà : l'inventaire physique.
 *
 * Et elle n'est plus planifiée. Le nettoyage nocturne des effectifs est assuré par
 * `batches:rebuild-quantities`, qui ne fait que cela.
 */
class SyncBatchStocks extends Command
{
    protected $signature = 'stocks:sync
                            {--force : Appliquer la rectification des effectifs (sinon : simulation)}
                            {--farm= : Limiter à un site}';

    protected $description = 'Réconcilie les effectifs de lots et constate les écarts de stock d\'œufs (lecture seule sans --force)';

    public function handle(BatchQuantityService $quantities, CompareEggStockToLedger $eggs): int
    {
        $apply = (bool) $this->option('force');

        if (! $apply) {
            $this->warn('Simulation : aucune écriture. Ajouter --force pour appliquer la rectification des effectifs.');
        }

        $farms = Farm::withoutGlobalScopes()
            ->where('is_active', true)
            ->when($this->option('farm'), fn ($q) => $q->where('id', (int) $this->option('farm')))
            ->get();

        if ($farms->isEmpty()) {
            $this->error('Aucun site actif : rien à réconcilier.');

            return self::FAILURE;
        }

        $problems = 0;

        foreach ($farms as $farm) {
            $this->line('');
            $this->line("<options=bold>{$farm->name}</>");

            // Le scope ferme se règle sur la session, y compris en console : sans
            // cela, chaque site verrait les lots de tous les autres.
            session(['current_farm_id' => $farm->id]);

            $problems += $this->reconcileSubjects($quantities, $apply);
            $problems += $this->reportEggs($eggs, $farm->id);
        }

        $this->line('');

        // Code de sortie non nul s'il reste quelque chose à regarder : utilisable
        // dans un script de surveillance sans lire le texte.
        return $problems > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function reconcileSubjects(BatchQuantityService $quantities, bool $apply): int
    {
        $report = $quantities->rebuildAll(dryRun: ! $apply);

        if ($report['details'] === []) {
            $this->line("  Effectifs : {$report['total_checked']} lot(s) cohérent(s).");

            return 0;
        }

        foreach ($report['details'] as $d) {
            $verbe = $d['corrected'] ? 'rectifié' : 'à rectifier';

            $this->line(sprintf(
                '  Effectifs : lot %s %s — %d → %d (écart %+d)',
                $d['batch_code'], $verbe, $d['old_quantity'], $d['new_quantity'], -$d['drift']
            ));
        }

        // Un écart rectifié n'est plus un problème ; un écart seulement constaté
        // en est un, puisqu'il reste.
        return $apply ? 0 : count($report['details']);
    }

    private function reportEggs(CompareEggStockToLedger $eggs, int $farmId): int
    {
        $lines = $eggs->execute($farmId);

        if ($lines === []) {
            // L'absence d'articles de calibre n'est un défaut que si ce site
            // RÉCOLTE des œufs. Un site qui n'élève que du poulet de chair n'a
            // aucune raison d'en avoir, et le signaler chaque fois apprendrait au
            // lecteur à ignorer le rapport.
            $recolte = \App\Models\EggProduction::withoutGlobalScopes()->where('farm_id', $farmId)->exists();

            if (! $recolte) {
                $this->line('  Œufs : aucun article de calibre, et aucune récolte enregistrée sur ce site — normal.');

                return 0;
            }

            // Ce cas était annoncé « succès » auparavant, après cinq « introuvable ».
            $this->line('  Œufs : des récoltes sont enregistrées mais AUCUN article de calibre n’existe en magasin — le calibrage n’a donc nulle part où entrer sa production.');
            $this->line('         → Logistique › Inventaire → créer les articles de calibre (S, M, L, XL, Cassé, Anomalie).');

            return 1;
        }

        $gaps = 0;

        foreach ($lines as $l) {
            if (abs($l['gap']) < 0.001) {
                continue;
            }

            $gaps++;

            $this->line(sprintf(
                '  Œufs : « %s » niveau %s ≠ registre %s (écart %+.2f %s)',
                $l['item'], number_format($l['level'], 2), number_format($l['ledger'], 2), $l['gap'], $l['unit']
            ));

            $this->line($l['decidable']
                ? '         → Écart net : aucun ajustement d’inventaire dans l’historique. Vérifier les mouvements de cet article.'
                : "         → {$l['adjustments']} ajustement(s) d’inventaire dans l’historique : leur SENS n’est pas enregistré, "
                  . 'le registre ne permet donc pas de trancher. Faire un inventaire physique.');
        }

        if ($gaps === 0) {
            $this->line('  Œufs : chaque article s’accorde avec son registre de mouvements.');
        }

        // Un écart d'œufs n'est pas rectifiable d'ici : on le signale sans le
        // compter comme une anomalie bloquante, sauf s'il est net.
        return collect($lines)->filter(fn ($l) => abs($l['gap']) >= 0.001 && $l['decidable'])->count();
    }
}
