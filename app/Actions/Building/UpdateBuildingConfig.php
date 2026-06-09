<?php

namespace App\Actions\Building;

use App\Models\Building;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateBuildingConfig
{
    public function execute(Building $building, array $data): Building
    {
        // Vérification de la présence de cheptel actif
        $isOccupied = $building->batches()->where('status', 'Actif')->exists();

        if ($isOccupied) {
            // Verrou 1 : Interdiction de changer la vocation technique pendant une bande
            if ($data['type'] !== $building->type) {
                throw ValidationException::withMessages([
                    'type' => 'CONFLIT TECHNIQUE : Le bâtiment contient un lot actif. Impossible de modifier son type de production.'
                ]);
            }

            // Verrou 2 : Interdiction d'enregistrer le statut "Vide" alors que des oiseaux y vivent
            if ($data['status'] === 'Vide') {
                throw ValidationException::withMessages([
                    'status' => "ERREUR DE FLUX : Le statut ne peut pas être configuré sur 'Vide' tant que le cheptel est présent."
                ]);
            }
        }

        return DB::transaction(function () use ($building, $data) {
            $building->update($data);
            return $building->fresh();
        });
    }
}