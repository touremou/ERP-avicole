<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Building;
use App\Models\ProductionType;
use App\Services\SanitarySchedulerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Action : Création d'un nouveau lot de production.
 *
 * Responsabilités :
 * 1. Vérification de capacité du bâtiment cible
 * 2. Calcul des champs dérivés (coûts, mortalité d'arrivage, dates)
 * 3. Création atomique du lot
 * 4. Mise à jour du statut bâtiment → Occupé
 * 5. Planification sanitaire initiale
 *
 * @see AUDIT_MODULE_LOTS.md — B-02 (remplace le BatchService manquant)
 */
class CreateBatch
{
    public function __construct(
        private SanitarySchedulerService $scheduler
    ) {}

    /**
     * @param  array $data  Données validées depuis StoreBatchRequest
     * @return Batch Le lot créé
     *
     * @throws ValidationException Si la capacité du bâtiment est insuffisante
     */
    public function execute(array $data): Batch
    {
        return DB::transaction(function () use ($data) {
            $building = Building::lockForUpdate()->findOrFail($data['building_id']);

            // ─── Vérification de capacité ───
            $currentOccupation = Batch::where('building_id', $building->id)
                ->active()
                ->sum('current_quantity');

            $qtyAlive = (int) ($data['qty_alive'] ?? 0);
            $available = $building->capacity - $currentOccupation;

            if ($qtyAlive > $available) {
                throw ValidationException::withMessages([
                    'building_id' => "Capacité insuffisante dans {$building->name} : {$qtyAlive} sujets demandés, {$available} places disponibles.",
                ]);
            }

            // ─── Calcul des champs dérivés ───
            $qtyDead = (int) ($data['qty_dead'] ?? 0);
            $totalOrdered = $qtyAlive + $qtyDead; // Total commandé (vivants reçus + morts transport)
            $price = (float) ($data['buy_price_per_unit'] ?? 0);

            // ─── Résolution du type de production (source de vérité) ───
            // `type` n'est plus une colonne : le slug soumis par le formulaire
            // est traduit en production_type_id (créé si besoin). Le champ
            // caché production_type_id peut arriver vide ("") si le JS n'a
            // pas (encore) résolu l'option choisie.
            $productionTypeId = ! empty($data['production_type_id'])
                ? (int) $data['production_type_id']
                : ProductionType::resolveOrCreate($data['type'], $data['species_id'] ?? null)->id;

            $batch = Batch::create([
                // Identité
                'code'        => $data['code'],
                'model_name'  => $data['model_name'] ?: 'Non spécifié',
                'species_id'         => $data['species_id'] ?? null,
                'production_type_id' => $productionTypeId,

                // Relations
                'building_id'  => $building->id,
                'employee_id'  => $data['employee_id'],
                'provider_id'  => $data['provider_id'],
                'protocol_id'  => $data['protocol_id'] ?? null,
                'current_protocol_id' => $data['protocol_id'] ?? null,

                // Effectifs — current_quantity = qty_alive (au J0, avant tout pointage)
                'initial_quantity'  => $qtyAlive,
                'current_quantity'  => $qtyAlive,
                'qty_dead'          => $qtyDead,
                'qty_males'         => (int) ($data['qty_males'] ?? 0),
                'qty_females'       => (int) ($data['qty_females'] ?? 0),
                'mating_ratio'      => (float) ($data['mating_ratio'] ?? 0),

                // Technique
                'avg_weight_start'   => $data['avg_weight_start'] ?? 0,
                'allocated_surface'  => $data['allocated_surface'] ?? null,
                'planned_density'    => ($data['allocated_surface'] ?? 0) > 0
                    ? round($qtyAlive / (float) $data['allocated_surface'], 2)
                    : 0,

                // Financier
                'buy_price_per_unit'     => $price,
                'total_acquisition_cost' => $qtyAlive * $price,
                'arrival_mortality_rate' => $totalOrdered > 0
                    ? round(($qtyDead / $totalOrdered) * 100, 2)
                    : 0,

                // Dates
                'arrival_date' => $data['arrival_date'],
                // expected_end_date est calculé automatiquement par Batch::booted()

                // État
                'status'      => Batch::STATUS_ACTIF,
                'chick_state' => 'Normal',
                'production_phase' => 'demarrage',

                // Vaccinations
                'vaccination_received' => $data['vaccination_received'] ?? false,
                'vaccination_details'  => $data['vaccination_details'] ?? null,

                // Observations
                'observations' => $data['observations'] ?? null,
                'photo_path'   => $data['photo_path'] ?? null,
            ]);

            // ─── Mise à jour du bâtiment ───
            $building->markOccupied();

            // ─── Planification sanitaire ───
            if ($batch->protocol_id) {
                $this->scheduler->syncSchedule($batch);
            }

            return $batch;
        });
    }
}
