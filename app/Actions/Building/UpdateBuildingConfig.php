<?php

namespace App\Actions\Building;

use App\Models\Building;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateBuildingConfig
{
    public function execute(Building $building, array $data): Building
    {
        // Effectif actif présent dans le bâtiment (somme des lots actifs).
        $currentOccupation = (int) $building->batches()->active()->sum('current_quantity');
        $isOccupied = $currentOccupation > 0 || $building->batches()->active()->exists();

        if ($isOccupied) {
            // Verrou 1 : Interdiction de changer la vocation technique pendant une bande
            if ($data['type'] !== $building->type) {
                throw ValidationException::withMessages([
                    'type' => 'CONFLIT TECHNIQUE : Le bâtiment contient un lot actif. Impossible de modifier son type de production.'
                ]);
            }

            // Verrou 2 : Interdiction d'enregistrer un statut « libre » (Vide ou
            // Disponible, cf. Building::STATUS_AVAILABLE) alors que le cheptel
            // est présent — sinon le bâtiment serait proposé pour un nouveau lot.
            if (in_array($data['status'], Building::STATUS_AVAILABLE, true)) {
                throw ValidationException::withMessages([
                    'status' => "ERREUR DE FLUX : Le statut ne peut pas être configuré sur '{$data['status']}' tant que le cheptel est présent."
                ]);
            }
        }

        // Verrou 3 : la capacité ne peut pas descendre sous l'effectif déjà
        // logé (état physiquement impossible : densité > 100 %).
        if (array_key_exists('capacity', $data) && (int) $data['capacity'] < $currentOccupation) {
            throw ValidationException::withMessages([
                'capacity' => "CAPACITÉ INVALIDE : {$data['capacity']} place(s) demandée(s) alors que {$currentOccupation} sujet(s) sont déjà logés dans {$building->name}."
            ]);
        }

        return DB::transaction(function () use ($building, $data) {
            $building->update($data);
            return $building->fresh();
        });
    }
}