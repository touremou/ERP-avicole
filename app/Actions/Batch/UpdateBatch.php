<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Building;
use App\Models\ProductionType;
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
        'arrival_date', 'birth_date', 'status',
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

            // ─── Statut : ni clôture ni réouverture par une modification ───
            // Même règle que le formulaire (Batch::statutsModifiables) : cette
            // action sert aussi le terrain, qui ne passe pas par la requête.
            if (array_key_exists('status', $payload)
                && ! in_array($payload['status'], $batch->statutsModifiables(), true)) {
                throw ValidationException::withMessages([
                    'status' => $batch->motifStatutRefuse((string) $payload['status']),
                ]);
            }

            if (array_key_exists('model_name', $payload) && ! $payload['model_name']) {
                $payload['model_name'] = 'Non spécifié';
            }

            // ─── Résolution du type de production (source de vérité) ───
            // `type` n'est plus une colonne : le slug soumis par le formulaire
            // est traduit en production_type_id (créé si besoin), sauf si
            // production_type_id est déjà fourni explicitement.
            if (isset($payload['type'])) {
                if (empty($payload['production_type_id'])) {
                    $payload['production_type_id'] = ProductionType::resolveOrCreate(
                        $payload['type'],
                        $batch->species_id
                    )->id;
                } else {
                    $payload['production_type_id'] = (int) $payload['production_type_id'];
                }

                unset($payload['type']);
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
            // Même règle des morts à la livraison qu'à la mise en lot, celle
            // figée sur le lot (App\Support\MortsALaLivraison).
            if (isset($payload['buy_price_per_unit'])) {
                $couts = \App\Support\MortsALaLivraison::couts(
                    (int) $batch->initial_quantity, (int) $batch->qty_dead,
                    (float) $payload['buy_price_per_unit'], $batch->doa_policy,
                );
                $payload['total_acquisition_cost'] = $couts['acquisition'];
                $payload['transport_loss_cost']    = $couts['perte'];
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
                $this->updateBuildingStatuses($batch, $oldBuildingId, (int) $payload['building_id']);
            }

            return $batch->fresh();
        });
    }

    /**
     * Vérifie que le nouveau bâtiment a la capacité d'accueillir le lot.
     */
    private function checkBuildingCapacity(Batch $batch, int $newBuildingId): void
    {
        // AUDIT C5 : même motif que CreateBatch — verrou du bâtiment cible
        // (mutex) + lecture d'occupation VERROUILLANTE (voit les commits
        // concurrents, contrairement au consistent read du snapshot).
        $building = Building::lockForUpdate()->findOrFail($newBuildingId);

        // Vide sanitaire en cours : pas de bande (Building::refusDeMiseEnPlace).
        if ($motif = $building->refusDeMiseEnPlace()) {
            throw ValidationException::withMessages(['building_id' => $motif]);
        }

        $currentOccupation = Batch::where('building_id', $newBuildingId)
            ->active()
            ->where('id', '!=', $batch->id)
            ->lockForUpdate()
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
     *
     * DÉMÉNAGEMENT OU CORRECTION ? Décision de l'exploitation (recommandation
     * standard) : un lot qui a déjà des POINTAGES a vécu dans l'ancien bâtiment —
     * changer son bâtiment, c'est un déménagement : l'ancien entre en vide
     * sanitaire s'il n'y vit plus personne, comme par un transfert
     * (TransferBatch). Sans pointage, c'est une correction de saisie : les
     * animaux n'y sont jamais entrés, le bâtiment redevient disponible.
     *
     * Le formulaire de modification libérait toujours l'ancien bâtiment : un
     * déménagement fait par là sautait le vide sanitaire qu'un transfert impose.
     */
    private function updateBuildingStatuses(Batch $batch, int $oldBuildingId, int $newBuildingId): void
    {
        // Ancien bâtiment : vérifier s'il reste des lots actifs
        $oldHasActive = Batch::where('building_id', $oldBuildingId)
            ->active()
            ->exists();

        if ($oldBuilding = Building::find($oldBuildingId)) {
            $aVecuLa = $batch->dailyChecks()->exists();

            match (true) {
                $oldHasActive => $oldBuilding->markOccupied(),
                $aVecuLa      => $oldBuilding->constaterLaSortieDuDernierAnimal($batch->id),
                default       => $oldBuilding->markAvailable(),
            };
        }

        // Nouveau bâtiment : forcément Occupé
        Building::find($newBuildingId)?->markOccupied();
    }
}
