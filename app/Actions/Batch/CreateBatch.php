<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Building;
use App\Models\ProductionType;
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
 *
 * @see AUDIT_MODULE_LOTS.md — B-02 (remplace le BatchService manquant)
 */
class CreateBatch
{
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

            // Vide sanitaire en cours : pas de mise en place (Building::refusDeMiseEnPlace).
            if ($motif = $building->refusDeMiseEnPlace()) {
                throw ValidationException::withMessages(['building_id' => $motif]);
            }

            // ─── Vérification de capacité ───
            // AUDIT C5 (prouvé par drill parallèle) : le SUM en consistent read
            // (snapshot REPEATABLE READ) ne voyait pas le lot committé par une
            // création concurrente, même après l'attente du verrou bâtiment
            // (120 sujets créés pour 100 de capacité). lockForUpdate rend la
            // lecture VERROUILLANTE : elle lit la dernière version committée.
            $currentOccupation = Batch::where('building_id', $building->id)
                ->active()
                ->lockForUpdate()
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

            // Morts à la livraison : la règle du CONTRAT du fournisseur, figée
            // sur le lot (App\Support\MortsALaLivraison).
            $politiqueDoa = \App\Support\MortsALaLivraison::politiqueDe(
                ! empty($data['provider_id']) ? \App\Models\Provider::find($data['provider_id']) : null
            );
            $coutsArrivee = \App\Support\MortsALaLivraison::couts($qtyAlive, $qtyDead, $price, $politiqueDoa);

            // ─── Résolution du type de production (source de vérité) ───
            // `type` n'est plus une colonne : le slug soumis par le formulaire
            // est traduit en production_type_id (créé si besoin). Le champ
            // caché production_type_id peut arriver vide ("") si le JS n'a
            // pas (encore) résolu l'option choisie.
            $productionTypeId = ! empty($data['production_type_id'])
                ? (int) $data['production_type_id']
                : ProductionType::resolveOrCreate($data['type'], $data['species_id'] ?? null)->id;

            // Souche du référentiel (AnimalBreed::pourLeLot : breed_id fait foi).
            $souche = \App\Models\AnimalBreed::pourLeLot(
                ! empty($data['breed_id']) ? (int) $data['breed_id'] : null,
                $data['model_name'] ?? null,
                ! empty($data['species_id']) ? (int) $data['species_id'] : null,
            );

            $batch = Batch::create(array_filter([
                // Identifiant du terrain (synchronisation idempotente) : le
                // rejouer retrouve CE lot au lieu d'en créer un second.
                'uuid'        => $data['uuid'] ?? null,
                'is_synced'   => $data['is_synced'] ?? null,
                'last_sync_at' => $data['last_sync_at'] ?? null,
            ], fn ($v) => $v !== null) + [
                // Identité
                'code'        => $data['code'],
                'model_name'  => $souche['model_name'] ?: 'Non spécifié',
                'breed_id'    => $souche['breed_id'],
                'species_id'         => $data['species_id'] ?? null,
                'production_type_id' => $productionTypeId,

                // Relations
                'building_id'  => $building->id,
                'employee_id'  => $data['employee_id'] ?? null,
                'provider_id'  => $data['provider_id'] ?? null,
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
                'total_acquisition_cost' => $coutsArrivee['acquisition'],
                'transport_loss_cost'    => $coutsArrivee['perte'],
                'doa_policy'             => $politiqueDoa,
                'arrival_mortality_rate' => $totalOrdered > 0
                    ? round(($qtyDead / $totalOrdered) * 100, 2)
                    : 0,

                // Dates
                'arrival_date' => $data['arrival_date'],
                // La naissance ancre TOUT le zootechnique (âge, phase, seuils de
                // mortalité, échéances de protocole). L'omettre ici la faisait
                // valider par le formulaire puis disparaître en silence.
                'birth_date'   => $data['birth_date'] ?? null,
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


            return $batch;
        });
    }
}
