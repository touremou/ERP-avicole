<?php

namespace App\Console\Commands;

use App\Services\BatchQuantityService;
use Illuminate\Console\Command;

/**
 * Commande de réconciliation des effectifs de lots.
 *
 * Recalcule current_quantity pour tous les lots actifs en se basant
 * sur initial_quantity et la somme des impacts des daily_checks.
 *
 * Usage :
 *   php artisan batches:rebuild-quantities              # SIMULATION (rien n'est écrit)
 *   php artisan batches:rebuild-quantities --force      # Applique les corrections
 *   php artisan batches:rebuild-quantities --batch=42   # Un seul lot
 *
 * CONVENTION UNIFIÉE : les commandes qui réécrivent des chiffres simulent par
 * défaut et n'écrivent qu'avec --force. Celle-ci appliquait par défaut, à l'inverse
 * de feed:recompute-costs et stocks:sync — le même geste montrait donc dans un cas
 * et réécrivait dans l'autre. `--dry-run` reste accepté et vaut le défaut, pour ne
 * pas faire écrire une habitude ou un script existant.
 *
 * LA TÂCHE PLANIFIÉE DE NUIT PASSE DONC --force EXPLICITEMENT (cf. routes/console.php).
 * L'oublier aurait transformé la réconciliation nocturne en simulation muette :
 * exactement le genre de panne silencieuse que cet audit passe son temps à corriger.
 *
 * @see AUDIT_MODULE_LOTS.md — B-03/B-04 (update écrase current_quantity)
 */
class RebuildBatchQuantities extends Command
{
    protected $signature = 'batches:rebuild-quantities
                            {--force : APPLIQUER les corrections. Sans ce drapeau : simulation seule}
                            {--dry-run : Conservé pour compatibilité — c\'est déjà le comportement par défaut}
                            {--batch= : ID d\'un lot spécifique à recalculer}';

    protected $description = 'Recalcule les effectifs vivants depuis les pointages journaliers';

    public function handle(BatchQuantityService $service): int
    {
        // Simulation par défaut. --dry-run est toléré et redondant (cf. l'en-tête).
        $dryRun = ! $this->option('force');
        $batchId = $this->option('batch');

        if ($dryRun) {
            $this->warn('⚡ Mode prévisualisation (aucune modification ne sera appliquée)');
            $this->newLine();
        }

        if ($batchId) {
            return $this->rebuildSingle($service, (int) $batchId, $dryRun);
        }

        return $this->rebuildAll($service, $dryRun);
    }

    private function rebuildSingle(BatchQuantityService $service, int $batchId, bool $dryRun): int
    {
        $batch = \App\Models\Batch::find($batchId);

        if (! $batch) {
            $this->error("Lot #{$batchId} introuvable.");
            return self::FAILURE;
        }

        $result = $service->rebuildForBatch($batch, $dryRun);

        $this->table(
            ['Champ', 'Valeur'],
            [
                ['Lot', $result['batch_code']],
                ['Quantité initiale', $result['initial_quantity']],
                ['Impact total (daily_checks)', $result['total_impact']],
                ['Quantité actuelle (avant)', $result['old_quantity']],
                ['Quantité recalculée', $result['new_quantity']],
                ['Écart (drift)', $result['drift']],
                ['Corrigé', $result['corrected'] ? 'OUI' : ($dryRun ? 'NON (simulation)' : 'NON (déjà cohérent)')],
            ]
        );

        if ($result['drift'] === 0) {
            $this->info("Le lot {$result['batch_code']} est cohérent.");
        } elseif ($dryRun) {
            $this->warn("Le lot {$result['batch_code']} a un écart de {$result['drift']} sujets. Relancer avec --force pour corriger.");
        } else {
            $this->info("Le lot {$result['batch_code']} a été corrigé ({$result['old_quantity']} → {$result['new_quantity']}).");
        }

        return self::SUCCESS;
    }

    private function rebuildAll(BatchQuantityService $service, bool $dryRun): int
    {
        $this->info('Vérification de tous les lots actifs...');
        $this->newLine();

        $report = $service->rebuildAll($dryRun);

        if (empty($report['details'])) {
            $this->info("Tous les {$report['total_checked']} lots actifs sont cohérents.");
            return self::SUCCESS;
        }

        // Afficher les lots avec des écarts
        $rows = collect($report['details'])->map(fn ($d) => [
            $d['batch_code'],
            $d['initial_quantity'],
            $d['total_impact'],
            $d['old_quantity'],
            $d['new_quantity'],
            $d['drift'] > 0 ? "+{$d['drift']}" : $d['drift'],
            $d['corrected'] ? '✅' : ($dryRun ? '⏳' : '—'),
        ]);

        $this->table(
            ['Lot', 'Initial', 'Impacts', 'Avant', 'Après', 'Écart', 'Corrigé'],
            $rows->toArray()
        );

        $this->newLine();
        $this->info("Résumé : {$report['total_checked']} lots vérifiés, " .
                     count($report['details']) . " avec écart, " .
                     "{$report['total_corrected']} corrigés.");

        if ($dryRun && $report['total_corrected'] === 0) {
            $this->warn('Relancer avec --force pour appliquer les corrections.');
        }

        return self::SUCCESS;
    }
}
