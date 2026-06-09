<?php

namespace App\Actions\Dispatch;

use App\Models\Dispatch;
use App\Models\Reception;
use App\Models\ReceptionItem;
use App\Services\ReconciliationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;

class ValidateReception
{
    /**
     * Enregistre la réception et lance la réconciliation automatique.
     *
     * Le responsable magasin saisit ce qu'il reçoit RÉELLEMENT.
     * Le système compare avec ce qui a été expédié et génère un rapport d'écart.
     *
     * RÈGLE : Le réceptionnaire NE PEUT PAS être l'expéditeur.
     */
    public function execute(Dispatch $dispatch, array $data): Reception
    {
        if ($dispatch->reception()->exists()) {
            throw new Exception("L'expédition {$dispatch->dispatch_number} a déjà été réceptionnée.");
        }

        if (! in_array($dispatch->status, ['expedie', 'en_route'])) {
            throw new Exception("L'expédition {$dispatch->dispatch_number} n'est pas en cours (statut: {$dispatch->status}).");
        }

        // ANTI-FRAUDE : le réceptionnaire ne peut pas être l'expéditeur
        if (Auth::id() === $dispatch->dispatched_by) {
            throw new Exception(
                "Anti-fraude : le réceptionnaire ne peut pas être la même personne que l'expéditeur. " .
                "Demandez à un autre collaborateur de valider la réception."
            );
        }

        return DB::transaction(function () use ($dispatch, $data) {

            // ─── 1. NUMÉROTATION ───
            $year = now()->format('Y');
            $lastNum = Reception::where('reception_number', 'LIKE', "REC-{$year}-%")->max('reception_number');
            $seq = $lastNum ? (int) substr($lastNum, -6) + 1 : 1;

            // ─── 2. CRÉER LA RÉCEPTION ───
            $reception = Reception::create([
                'dispatch_id'      => $dispatch->id,
                'reception_number' => sprintf('REC-%s-%06d', $year, $seq),
                'received_by'      => Auth::id(),
                'reception_date'   => $data['reception_date'],
                'reception_time'   => $data['reception_time'] ?? null,
                'status'           => 'en_attente',
                'notes'            => $data['notes'] ?? null,
            ]);

            // ─── 3. CRÉER LES LIGNES DE RÉCEPTION ───
            foreach ($data['items'] as $itemData) {
                $dispatchItem = $dispatch->items()->find($itemData['dispatch_item_id']);

                if (! $dispatchItem) {
                    throw new Exception("Ligne d'expédition #{$itemData['dispatch_item_id']} introuvable.");
                }

                $received = (float) $itemData['quantity_received'];
                $damaged  = (float) ($itemData['quantity_damaged'] ?? 0);
                $missing  = max(0, (float) $dispatchItem->quantity_dispatched - $received - $damaged);

                $recItem = ReceptionItem::create([
                    'reception_id'          => $reception->id,
                    'dispatch_item_id'      => $dispatchItem->id,
                    'quantity_received'     => $received,
                    'quantity_damaged'      => $damaged,
                    'quantity_missing'      => $missing,
                    'condition_at_reception' => $itemData['condition'] ?? 'bon',
                    'notes'                 => $missing > 0
                        ? ($itemData['notes'] ?? 'Écart non justifié')
                        : ($itemData['notes'] ?? null),
                ]);
            }

            // ─── 4. RÉCONCILIATION AUTOMATIQUE ───
            $reconciliation = new ReconciliationService();
            $report = $reconciliation->reconcile($reception);

            return $reception->fresh(['items', 'discrepancyReport']);
        });
    }
}
