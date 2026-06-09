<?php

namespace App\Actions\EggProduction;

use App\Models\Batch;
use App\Models\EggProduction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Action : Enregistrement d'une collecte d'œufs brute.
 *
 * Gère plusieurs ramassages par jour par "Cumul" (additionne les œufs au lieu de créer des doublons).
 * Calcule le taux de ponte sur l'effectif vivant réel (current_quantity).
 */
class RecordEggCollection
{
    public function execute(array $data): EggProduction
    {
        $batch = Batch::findOrFail($data['batch_id']);

        return DB::transaction(function () use ($data, $batch) {
            
            // 1. Recherche d'une collecte existante pour ce lot aujourd'hui
            $production = EggProduction::where('batch_id', $data['batch_id'])
                ->where('production_date', $data['production_date'])
                ->first();

            // 2. SÉCURITÉ ERP : Si le tri a déjà été fait, on bloque
            if ($production && $production->is_graded) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'production_date' => "Les œufs de ce jour ont déjà été triés et mis en stock. Annulez d'abord le tri pour ajouter ce nouveau passage."
                ]);
            }

            // 3. CUMUL DES DONNÉES
            $newTotal = (int) $data['total_eggs_collected'];
            $newBroken = (int) ($data['broken_eggs'] ?? 0);
            $newSmall = (int) ($data['small_eggs'] ?? 0);
            $newObs = $data['observations'] ?? null;
            
            $finalObservations = $newObs;

            if ($production) {
                // On additionne le nouveau ramassage
                $newTotal += (int) $production->total_eggs_collected;
                $newBroken += (int) $production->broken_eggs;
                $newSmall += (int) $production->small_eggs;

                // CORRECTION : On force le marqueur "[Nouveau passage]" à chaque fois
                $baseObs = $production->observations ? $production->observations . " | " : "";
                $noteText = $newObs ? " : " . $newObs : "";
                $finalObservations = $baseObs . "[Nouveau passage]" . $noteText;
            }

            // 4. Recalcul du taux de ponte
            $layingRate = $batch->current_quantity > 0
                ? round(($newTotal / $batch->current_quantity) * 100, 2)
                : 0;

            // 5. Enregistrement
            return EggProduction::updateOrCreate(
                [
                    'batch_id'        => $data['batch_id'],
                    'production_date' => $data['production_date'],
                ],
                [
                    'total_eggs_collected' => $newTotal,
                    'broken_eggs'          => $newBroken,
                    'small_eggs'           => $newSmall,
                    'laying_rate'          => $layingRate,
                    'observations'         => $finalObservations,
                    'is_graded'            => false,
                ]
            );
        });
    }
}