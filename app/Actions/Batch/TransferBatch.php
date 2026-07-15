<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Building;
use App\Models\Protocol;
use App\Models\ProductionType;
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
            // Biosécurité : un lot en quarantaine ne bouge pas — la mutation
            // vers un autre bâtiment est le vecteur de propagation n°1.
            // Levée exclusivement via le module Santé (incident).
            if ($quarantine = $batch->activeQuarantine()) {
                throw new \Exception(
                    "Lot {$batch->code} en QUARANTAINE sanitaire (incident n°{$quarantine->id}) : "
                    . "mutation interdite — risque de propagation. Levez d'abord la quarantaine via le module Santé."
                );
            }

            $newBuilding = Building::lockForUpdate()->findOrFail($data['target_building_id']);
            $oldBuilding = $batch->building;
            $newProtocol = Protocol::findOrFail($data['new_protocol_id']);

            // ─── 1. GRADUATION ÉVENTUELLE DE TYPE DE PRODUCTION ───
            // La mutation peut faire passer un lot d'un type de production à
            // un autre (ex. poussinière -> chair/ponte/reproducteur après
            // éclosion). `production_type_id` est la SOURCE DE VÉRITÉ
            // (Batch::getTypeAttribute, feedSector, tracksEggs,
            // calculateExpectedEndDate, filtres bâtiment...) : si la phase
            // cible diverge du type courant, on bascule vers le type de
            // production correspondant (même espèce).
            $oldType = $batch->type;
            $newType = $data['new_phase'];
            $newProductionTypeId = null;

            if ($oldType && $newType && $newType !== $oldType) {
                $newProductionTypeId = ProductionType::resolveOrCreate($newType, $batch->species_id)->id;
            }

            // ─── 2. HISTORISATION ───
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
                'old_type'         => $oldType,
                'new_type'         => $newType,
                'protocol_applied' => $newProtocol->name,
                'quantity_at_transfer' => $batch->current_quantity,
                'notes'            => $data['notes'] ?? null,
                'performed_by'     => Auth::user()?->name ?? 'Système',
            ];

            // ─── 3. MISE À JOUR DU LOT ───
            // Note B-12 : on ne touche PAS à current_quantity.
            // Le transfert ne change pas l'effectif, il change le lieu.
            $updates = array_filter([
                'building_id'         => $newBuilding->id,
                'production_phase'    => $data['new_phase'],
                'production_type_id'  => $newProductionTypeId,
                'current_protocol_id' => $newProtocol->id,
                'transfer_date'       => $data['transfer_date'],
                'transfer_history'    => $history,
            ], fn ($value) => $value !== null);

            // Mise à jour de la souche si l'opérateur en saisit une lors de la
            // graduation (poussinière → ponte/chair/repro). On n'écrase jamais
            // une souche connue par une valeur vide.
            $newModelName = trim((string) ($data['model_name'] ?? ''));
            if ($newModelName !== '' && $newModelName !== 'Non spécifié') {
                $updates['model_name'] = $newModelName;
            }

            $batch->update($updates);

            // ─── 4. STATUTS BÂTIMENTS ───
            // Nouveau bâtiment → Occupé
            $newBuilding->markOccupied();

            // Ancien bâtiment → Vide sanitaire SEULEMENT si le lot a réellement
            // changé de bâtiment (pas une transformation sur place) ET qu'il n'y
            // reste plus aucun lot actif (S-07).
            if ($oldBuilding && $oldBuilding->id !== $newBuilding->id) {
                $hasOtherActive = Batch::where('building_id', $oldBuilding->id)
                    ->where('id', '!=', $batch->id)
                    ->active()
                    ->exists();

                if (! $hasOtherActive) {
                    $oldBuilding->startSanitaryBreak();
                }
            }

            // ─── 5. REPLANIFICATION SANITAIRE ───
            $this->scheduler->syncSchedule($batch->fresh());

            return $batch->fresh();
        });
    }
}
