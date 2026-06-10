<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Building;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Action : Modification d'un lot existant.
 *
 * RÈGLE FONDAMENTALE (B-03/B-04) :
 * Cette action ne modifie JAMAIS current_quantity, qty_alive, initial_quantity, qty_dead.
 * L'effectif vivant ne change que via DailyCheck, Transfer, ou rebuild.
 *
 * Les seuls champs modifiables sont les métadonnées administratives
 * et les paramètres techniques du lot.
 */
class UpdateBatch
{
    /**
     * Liste blanche EXPLICITE des champs modifiables.
     *
     * Tout champ absent de cette liste sera ignoré, même s'il est
     * présent dans $data. C'est la protection contre B-03/B-04.
     */
    private const ALLOWED_FIELDS = [
        'type', 'model_name', 'production_type_id',
        'building_id', 'employee_id', 'provider_id',
        'protocol_id', 'current_protocol_id',
        'buy_price_per_unit',
        'arrival_date', 'status',
        'allocated_surface', 'observations',
        'qty_males', 'qty_females',
        'vaccination_received', 'vaccination_details',
        'photo_path',
    ];

    /**
     * @param  Batch $batch  Le lot à modifier
     * @param  array $data   Données validées depuis UpdateBatchRequest
     * @return Batch Le lot mis à jour
     */
    public function execute(Batch $batch, array $data): Batch
    {
        return DB::transaction(function () use ($batch, $data) {
            // ─── Filtrage par liste blanche ───
            $payload = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));

            if (array_key_exists('model_name', $payload) && ! $payload['model_name']) {
                $payload['model_name'] = 'Non spécifié';
            }

            // ─── Vérification de capacité si le bâtiment change ───
            if (isset($payload['building_id']) && $payload['building_id'] != $batch->building_id) {
                $this->checkBuildingCapacity($batch, (int) $payload['building_id']);
            }

            // ─── Recalculs dérivés ───

            // Mating ratio reproducteurs
            if (isset($payload['qty_males']) || isset($payload['qty_females'])) {
                $males = (int) ($payload['qty_males'] ?? $batch->qty_males);
                $females = (int) ($payload['qty_females'] ?? $batch->qty_females);
                $payload['mating_ratio'] = $females > 0
                    ? round(($males / $females) * 100, 2)
                    : 0;
            }

            // Coût d'acquisition (si le prix unitaire change)
            if (isset($payload['buy_price_per_unit'])) {
                $payload['total_acquisition_cost'] =
                    $batch->initial_quantity * (float) $payload['buy_price_per_unit'];
            }

            // Mapping protocole
            if (isset($payload['protocol_id'])) {
                $payload['current_protocol_id'] = $payload['protocol_id'];
            }

            // ─── Mise à jour ───
            $oldBuildingId = $batch->building_id;
            $batch->update($payload);

            // ─── Gestion statut bâtiment si changement ───
            if (isset($payload['building_id']) && $payload['building_id'] != $oldBuildingId) {
                $this->updateBuildingStatuses($oldBuildingId, (int) $payload['building_id']);
            }

            return $batch->fresh();
        });
    }

    /**
     * Vérifie que le nouveau bâtiment a la capacité d'accueillir le lot.
     */
    private function checkBuildingCapacity(Batch $batch, int $newBuildingId): void
    {
        $building = Building::findOrFail($newBuildingId);

        $currentOccupation = Batch::where('building_id', $newBuildingId)
            ->where('status', 'Actif')
            ->where('id', '!=', $batch->id)
            ->sum('current_quantity');

        $available = $building->capacity - $currentOccupation;

        if ($batch->current_quantity > $available) {
            throw ValidationException::withMessages([
                'building_id' => "Capacité insuffisante dans {$building->name} : " .
                    "{$batch->current_quantity} sujets, {$available} places disponibles.",
            ]);
        }
    }

    /**
     * Met à jour les statuts des bâtiments source et destination.
     */
    private function updateBuildingStatuses(int $oldBuildingId, int $newBuildingId): void
    {
        // Ancien bâtiment : vérifier s'il reste des lots actifs
        $oldHasActive = Batch::where('building_id', $oldBuildingId)
            ->where('status', 'Actif')
            ->exists();

        Building::where('id', $oldBuildingId)
            ->update(['status' => $oldHasActive ? 'Occupé' : 'Disponible']);

        // Nouveau bâtiment : forcément Occupé
        Building::where('id', $newBuildingId)
            ->update(['status' => 'Occupé']);
    }
}
