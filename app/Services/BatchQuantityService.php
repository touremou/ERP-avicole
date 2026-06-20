<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\DailyCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service centralisé pour le calcul et la réconciliation des effectifs vivants.
 *
 * Source de vérité : current_quantity (décision architecture §2.1)
 * Méthode de recalcul : initial_quantity - SUM(impacts daily_checks)
 *
 * Ce service est utilisé par :
 * - La commande artisan `batches:rebuild-quantities`
 * - Le controller `BatchController::syncAllStocks` (refactoré)
 * - Les opérations de réouverture de lot
 *
 * @see AUDIT_MODULE_LOTS.md — Décision 2.1 et B-03/B-04
 */
class BatchQuantityService
{
    /**
     * Recalcule current_quantity pour un lot spécifique depuis ses daily_checks.
     *
     * Formule : current_quantity = initial_quantity - SUM(impacts nets)
     * Impact net = (mortality + quarantine_in + sorted_out) - quarantine_out
     *
     * @param  Batch $batch  Le lot à recalculer
     * @param  bool  $dryRun Si true, retourne le résultat sans modifier la DB
     * @return array Détails du recalcul ['old' => int, 'new' => int, 'corrected' => bool]
     */
    public function rebuildForBatch(Batch $batch, bool $dryRun = false): array
    {
        // Calcul de l'impact total depuis les daily_checks non soft-deleted
        $totalImpact = DailyCheck::where('batch_id', $batch->id)
            ->whereNull('deleted_at')
            ->selectRaw('
                COALESCE(SUM(mortality), 0) 
                + COALESCE(SUM(qty_quarantine_in), 0) 
                + COALESCE(SUM(qty_sorted_out), 0) 
                - COALESCE(SUM(qty_quarantine_out), 0) 
                as net_impact
            ')
            ->value('net_impact') ?? 0;

        $expectedQuantity = max(0, $batch->initial_quantity - (int) $totalImpact);
        $currentQuantity = (int) $batch->current_quantity;
        $needsCorrection = $currentQuantity !== $expectedQuantity;

        $result = [
            'batch_id' => $batch->id,
            'batch_code' => $batch->code,
            'initial_quantity' => $batch->initial_quantity,
            'total_impact' => (int) $totalImpact,
            'old_quantity' => $currentQuantity,
            'new_quantity' => $expectedQuantity,
            'drift' => $currentQuantity - $expectedQuantity,
            'corrected' => false,
        ];

        if ($needsCorrection && ! $dryRun) {
            // Mise à jour directe sans déclencher l'observer
            // (on ne veut pas que le BatchObserver envoie une alerte mortalité
            // pour une simple réconciliation)
            DB::table('batches')
                ->where('id', $batch->id)
                ->update([
                    'current_quantity' => $expectedQuantity,
                    'updated_at' => now(),
                ]);

            // Synchroniser qty_alive aussi (tant que la colonne existe)
            if (\Schema::hasColumn('batches', 'qty_alive')) {
                DB::table('batches')
                    ->where('id', $batch->id)
                    ->update(['qty_alive' => $expectedQuantity]);
            }

            $result['corrected'] = true;

            Log::info(
                "[BatchQuantityService] Lot {$batch->code} recalculé : " .
                "{$currentQuantity} → {$expectedQuantity} (drift: {$result['drift']})"
            );
        }

        return $result;
    }

    /**
     * Recalcule current_quantity pour TOUS les lots actifs.
     *
     * @param  bool  $dryRun  Si true, retourne le rapport sans modifier la DB
     * @return array Rapport global ['total_checked', 'total_corrected', 'details' => [...]]
     */
    public function rebuildAll(bool $dryRun = false): array
    {
        $batches = Batch::active()
            ->orderBy('code')
            ->get();

        $report = [
            'total_checked' => $batches->count(),
            'total_corrected' => 0,
            'total_drift' => 0,
            'details' => [],
        ];

        foreach ($batches as $batch) {
            $result = $this->rebuildForBatch($batch, $dryRun);

            if ($result['drift'] !== 0) {
                $report['details'][] = $result;
                $report['total_drift'] += abs($result['drift']);

                if ($result['corrected']) {
                    $report['total_corrected']++;
                }
            }
        }

        Log::info(
            "[BatchQuantityService] Rebuild complet : {$report['total_checked']} lots vérifiés, " .
            "{$report['total_corrected']} corrigés, drift total: {$report['total_drift']}"
        );

        return $report;
    }

    /**
     * Vérifie la cohérence d'un lot sans le modifier.
     *
     * Utile pour le dashboard : afficher un badge "⚠ Désync" si le lot est incohérent.
     *
     * @return bool True si current_quantity est cohérent avec les daily_checks
     */
    public function isConsistent(Batch $batch): bool
    {
        $result = $this->rebuildForBatch($batch, dryRun: true);
        return $result['drift'] === 0;
    }
}
