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

            // 2.bis GARDE-FOU ZOOTECHNIQUE : âge d'entrée en ponte.
            // Invariant biologique appliqué ici (point d'écriture unique) afin
            // de couvrir TOUS les chemins — web, API terrain, sync hors-ligne —
            // y compris ceux qui ne passent pas par StoreEggProductionRequest.
            $minAge = $batch->minLayingAgeDays();
            if ($batch->age < $minAge) {
                $minWeeks = (int) ceil($minAge / 7);
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'batch_id' => "Lot {$batch->code} trop jeune pour la ponte : {$batch->age} jours, "
                        . "phase « {$batch->current_phase} ». Entrée en ponte attendue vers ~{$minWeeks} semaines.",
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

            // 3.bis GARDE-FOU : taux de ponte cumulé ≤ 100 % (1 œuf/sujet/jour).
            if ($batch->current_quantity > 0 && $newTotal > $batch->current_quantity) {
                $rate = number_format(($newTotal / $batch->current_quantity) * 100, 1);
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'total_eggs_collected' => "Taux de ponte impossible : {$newTotal} œufs pour "
                        . "{$batch->current_quantity} sujets = {$rate} %. Le maximum biologique est "
                        . "100 % (1 œuf/sujet/jour). Vérifiez votre saisie.",
                ]);
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