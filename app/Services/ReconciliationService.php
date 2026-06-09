<?php

namespace App\Services;

use App\Models\Dispatch;
use App\Models\Reception;
use App\Models\ReceptionItem;
use App\Models\DiscrepancyReport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ReconciliationService — Three-Way Matching
 *
 * Compare les quantités expédiées (ferme) avec les quantités reçues (magasin)
 * et génère automatiquement un rapport d'écart si des différences sont détectées.
 *
 * Seuils de tolérance par type de produit :
 * - Volaille vivante/abattue : 0% (chaque sujet est compté)
 * - Œufs : 2% (casse transport admise)
 * - Aliment : 1% (pesée approximative)
 * - Fumier : 5% (volume estimé)
 * - Matériel : 0% (comptage exact)
 */
class ReconciliationService
{
    /**
     * Seuils de tolérance par type de produit (en %).
     * Au-delà = écart INJUSTIFIÉ → investigation.
     */
    /* private const TOLERANCE = [
        'volaille' => setting('abattoir.tolerance_poultry', 0),
        'oeufs'    => setting('abattoir.tolerance_eggs', 2),
        'aliment'  => setting('abattoir.tolerance_feed', 1),
        'fumier'   => setting('abattoir.tolerance_manure', 5),
        'volaille_vivante' => 0,
        'volaille_abattue' => 0,
        'materiel'         => 0,
        'autre'            => 1,
    ]; */

    /**
     * Récupère les tolérances dynamiques depuis les paramètres de l'ERP
     */
    public static function getTolerances(): array
    {
        return [
            'volaille'         => (float) setting('abattoir.tolerance_poultry', 0),
            'oeufs'            => (float) setting('abattoir.tolerance_eggs', 2),
            'aliment'          => (float) setting('abattoir.tolerance_feed', 1),
            'fumier'           => (float) setting('abattoir.tolerance_manure', 5),
            
            // Les nouvelles variables variabilisées
            'volaille_vivante' => (float) setting('abattoir.tolerance_live_poultry', 0),
            'volaille_abattue' => (float) setting('abattoir.tolerance_slaughtered_poultry', 0),
            'materiel'         => (float) setting('abattoir.tolerance_equipment', 0),
            'autre'            => (float) setting('abattoir.tolerance_other', 1),
        ];
    }

    /**
     * Réconcilie une réception avec son expédition.
     *
     * Appelé automatiquement quand la réception est validée.
     * Génère un DiscrepancyReport si des écarts sont détectés.
     *
     * @return DiscrepancyReport|null  Null si aucun écart
     */
    public function reconcile(Reception $reception): ?DiscrepancyReport
    {
        $dispatch = $reception->dispatch;

        if (! $dispatch) {
            Log::error("ReconciliationService: réception #{$reception->id} sans expédition liée.");
            return null;
        }

        return DB::transaction(function () use ($reception, $dispatch) {

            // ─── 1. CALCULER LES ÉCARTS PAR LIGNE ───
            $totalDispatched = 0;
            $totalReceived   = 0;
            $totalDamaged    = 0;
            $totalMissing    = 0;
            $hasCritical     = false;

            foreach ($reception->items as $recItem) {
                $dispItem = $recItem->dispatchItem;
                if (! $dispItem) continue;

                $dispatched = (float) $dispItem->quantity_dispatched;
                $received   = (float) $recItem->quantity_received;
                $damaged    = (float) $recItem->quantity_damaged;

                // Calcul automatique du manquant
                $missing = max(0, $dispatched - $received - $damaged);
                $recItem->update(['quantity_missing' => $missing]);

                $totalDispatched += $dispatched;
                $totalReceived   += $received;
                $totalDamaged    += $damaged;
                $totalMissing    += $missing;

                // Vérifier si l'écart dépasse la tolérance
                $tolerance = self::getTolerances()[$dispItem->product_type] ?? 1;
                if ($dispatched > 0) {
                    $lineRate = ($missing / $dispatched) * 100;
                    if ($lineRate > $tolerance) {
                        $hasCritical = true;

                        Log::warning(
                            "ReconciliationService: écart critique sur {$dispItem->product_name} — " .
                            "Expédié: {$dispatched}, Reçu: {$received}, Endommagé: {$damaged}, " .
                            "Manquant: {$missing} ({$lineRate}% > tolérance {$tolerance}%)"
                        );
                    }
                }
            }

            // ─── 2. PAS D'ÉCART → PAS DE RAPPORT ───
            if ($totalMissing == 0 && $totalDamaged == 0) {
                $reception->update(['status' => 'valide']);
                $dispatch->update(['status' => 'receptionne']);

                Log::info("ReconciliationService: expédition {$dispatch->dispatch_number} réceptionnée sans écart.");
                return null;
            }

            // ─── 3. ÉCART DÉTECTÉ → GÉNÉRER LE RAPPORT ───
            $discrepancyRate = $totalDispatched > 0
                ? round(($totalMissing / $totalDispatched) * 100, 2)
                : 0;

            $severity = match (true) {
                $hasCritical || $discrepancyRate > 5 => 'critique',
                $discrepancyRate > 2                  => 'attention',
                default                               => 'normal',
            };

            $report = DiscrepancyReport::create([
                'dispatch_id'      => $dispatch->id,
                'reception_id'     => $reception->id,
                'reported_by'      => Auth::id(),
                'total_dispatched' => $totalDispatched,
                'total_received'   => $totalReceived,
                'total_damaged'    => $totalDamaged,
                'total_missing'    => $totalMissing,
                'discrepancy_rate' => $discrepancyRate,
                'severity'         => $severity,
                'resolution'       => 'en_cours',
            ]);

            // Mettre la réception en litige
            $reception->update(['status' => 'litige']);
            $dispatch->update(['status' => 'receptionne']);

            Log::warning(
                "ReconciliationService: ÉCART DÉTECTÉ sur {$dispatch->dispatch_number} — " .
                "Taux: {$discrepancyRate}%, Manquant: {$totalMissing}, Sévérité: {$severity}"
            );

            return $report;
        });
    }

    /**
     * Résout un rapport d'écart.
     */
    public function resolve(DiscrepancyReport $report, string $resolution, string $notes): void
    {
        if ($report->is_resolved) {
            throw new \Exception("Ce rapport est déjà résolu.");
        }

        $report->update([
            'resolution'       => $resolution,
            'resolution_notes' => $notes,
            'resolved_by'      => Auth::id(),
            'resolved_at'      => now(),
        ]);

        // Si résolu → clore la réception
        if (in_array($resolution, ['justifie', 'injustifie'])) {
            $report->reception->update(['status' => 'valide']);
            $report->dispatch->update(['status' => 'clos']);
        }

        Log::info(
            "ReconciliationService: rapport #{$report->id} résolu — {$resolution}. " .
            "Dispatch: {$report->dispatch->dispatch_number}"
        );
    }
}
