<?php

namespace App\Services;

use App\Models\Dispatch;
use App\Models\Reception;
use App\Models\ReceptionItem;
use App\Models\DiscrepancyReport;
use App\Services\Discrepancy\DiscrepancyEvaluator;
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
     * Les tolérances par type de produit et les seuils de sévérité sont
     * désormais centralisés dans config/logistique.php et résolus par
     * App\Services\Discrepancy\DiscrepancyEvaluator (source unique). La
     * méthode getTolerances() / la constante TOLERANCE codée en dur ont été
     * supprimées au profit de ce moteur.
     */

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

            // ─── 1. ÉVALUER LES ÉCARTS VIA LE MOTEUR (source unique) ───
            $evaluator = app(DiscrepancyEvaluator::class);

            // On ignore les lignes orphelines (sans expédition liée).
            $recItems = $reception->items->filter(fn ($r) => $r->dispatchItem)->values();

            $evaluation = $evaluator->evaluateReception(
                $recItems->map(fn ($recItem) => [
                    'product_type' => $recItem->dispatchItem->product_type,
                    'dispatched'   => (float) $recItem->dispatchItem->quantity_dispatched,
                    'received'     => (float) $recItem->quantity_received,
                    'damaged'      => (float) $recItem->quantity_damaged,
                ])
            );

            // Persister le manquant calculé + tracer les lignes hors tolérance.
            foreach ($evaluation->lines as $i => $line) {
                $recItem = $recItems[$i];
                $recItem->update(['quantity_missing' => $line->missing]);

                if (! $line->withinTolerance) {
                    Log::warning(
                        "ReconciliationService: écart critique sur {$recItem->dispatchItem->product_name} — " .
                        "Expédié: {$line->dispatched}, Reçu: {$line->received}, Endommagé: {$line->damaged}, " .
                        "Manquant: {$line->missing} ({$line->lineRate}% > tolérance {$line->tolerance}%)"
                    );
                }
            }

            // ─── 2. PAS D'ÉCART → PAS DE RAPPORT ───
            if (! $evaluation->hasDiscrepancy()) {
                $reception->update(['status' => 'valide']);
                $dispatch->update(['status' => 'receptionne']);

                Log::info("ReconciliationService: expédition {$dispatch->dispatch_number} réceptionnée sans écart.");
                return null;
            }

            // ─── 3. ÉCART DÉTECTÉ → GÉNÉRER LE RAPPORT ───
            $report = DiscrepancyReport::create([
                'dispatch_id'      => $dispatch->id,
                'reception_id'     => $reception->id,
                'reported_by'      => Auth::id(),
                'total_dispatched' => $evaluation->totalDispatched,
                'total_received'   => $evaluation->totalReceived,
                'total_damaged'    => $evaluation->totalDamaged,
                'total_missing'    => $evaluation->totalMissing,
                'discrepancy_rate' => $evaluation->discrepancyRate,
                'severity'         => $evaluation->severity,
                'resolution'       => 'en_cours',
            ]);

            // Mettre la réception en litige
            $reception->update(['status' => 'litige']);
            $dispatch->update(['status' => 'receptionne']);

            Log::warning(
                "ReconciliationService: ÉCART DÉTECTÉ sur {$dispatch->dispatch_number} — " .
                "Taux: {$evaluation->discrepancyRate}%, Manquant: {$evaluation->totalMissing}, Sévérité: {$evaluation->severity}"
            );

            // Alerte anti-fraude immédiate (admin/propriétaire hors site) si
            // l'écart n'est pas anodin — la résolution se fait a posteriori
            // via DiscrepancyReport::resolution.
            if ($evaluation->severity !== 'normal') {
                app(NotificationHub::class)->alertFraud($report->load(['dispatch']));
            }

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
