<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Building;
use App\Models\Protocol;
use App\Services\SanitarySchedulerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Action : Transfert (mutation) d'un lot vers un nouveau bâtiment.
 *
 * Corrections :
 * - B-12 : Ne touche PLUS à current_quantity (le no-op risqué)
 * - S-07 : Vide sanitaire seulement si plus aucun lot actif dans l'ancien bâtiment
 *
 * La validation métier (capacité, type, auto-transfert) est dans TransferBatchRequest.
 */
class TransferBatch
{
    public function __construct(
        private SanitarySchedulerService $scheduler
    ) {}

    /**
     * @param  Batch $batch  Le lot à transférer
     * @param  array $data   Données validées depuis TransferBatchRequest
     * @return Batch Le lot transféré
     */
    public function execute(Batch $batch, array $data): Batch
    {
        return DB::transaction(function () use ($batch, $data) {
            $newBuilding = Building::lockForUpdate()->findOrFail($data['target_building_id']);
            $oldBuilding = $batch->building;
            $newProtocol = Protocol::findOrFail($data['new_protocol_id']);

            // ─── 1. HISTORISATION ───
            $history = $batch->transfer_history ?? [];
            $history[] = [
                'uuid'             => (string) Str::uuid(),
                'date'             => $data['transfer_date'],
                'from_building_id' => $oldBuilding?->id,
                'from_building'    => $oldBuilding?->name ?? 'N/A',
                'to_building_id'   => $newBuilding->id,
                'to_building'      => $newBuilding->name,
                'old_phase'        => $batch->production_phase,
                'new_phase'        => $data['new_phase'],
                'protocol_applied' => $newProtocol->name,
                'quantity_at_transfer' => $batch->current_quantity,
                'notes'            => $data['notes'] ?? null,
                'performed_by'     => Auth::user()?->name ?? 'Système',
            ];

            // ─── 2. MISE À JOUR DU LOT ───
            // Note B-12 : on ne touche PAS à current_quantity.
            // Le transfert ne change pas l'effectif, il change le lieu.
            $batch->update([
                'building_id'         => $newBuilding->id,
                'production_phase'    => $data['new_phase'],
                'current_protocol_id' => $newProtocol->id,
                'transfer_date'       => $data['transfer_date'],
                'transfer_history'    => $history,
            ]);

            // ─── 3. STATUTS BÂTIMENTS ───
            // Nouveau bâtiment → Occupé
            $newBuilding->update(['status' => 'Occupé']);

            // Ancien bâtiment → Vide sanitaire SEULEMENT si plus aucun lot actif (S-07)
            if ($oldBuilding) {
                $hasOtherActive = Batch::where('building_id', $oldBuilding->id)
                    ->where('id', '!=', $batch->id)
                    ->where('status', 'Actif')
                    ->exists();

                if (! $hasOtherActive) {
                    $oldBuilding->update([
                        'status' => 'En désinfection',
                        'disinfection_started_at' => now(),
                    ]);
                }
            }

            // ─── 4. REPLANIFICATION SANITAIRE ───
            $this->scheduler->syncSchedule($batch->fresh());

            return $batch->fresh();
        });
    }
}
