<?php

namespace App\Actions\EggProduction;

use App\Models\EggProduction;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;

/**
 * Action : Tri d'une collecte brute par calibre.
 *
 * Pour chaque calibre (XL, L, M, S) :
 * - Calcule la quantité en alvéoles (alvéoles entières + unités / 30)
 * - Calcule le delta par rapport au tri précédent
 * - Synchronise le stock via syncMovement()
 *
 * Corrige O-05 : uniformisation sur syncMovement() (pas sync())
 */
class GradeEggProduction
{
    /**
     * @param EggProduction $prod    La collecte à trier
     * @param array         $data    Données validées depuis UpdateTriRequest
     * @return EggProduction         La collecte mise à jour
     */
    public function execute(EggProduction $prod, array $data): EggProduction
    {
        return DB::transaction(function () use ($prod, $data) {
            $grades = array_map('strtolower', EggProduction::gradeCodes());
            $newGrades = [];

            // ─── Synchronisation des calibres ───
            foreach ($grades as $g) {
                $alv = (int) ($data["grade_{$g}_alv"] ?? 0);
                $uni = (int) ($data["grade_{$g}_uni"] ?? 0);

                // Quantité en alvéoles (unité pivot)
                $newQtyAlv = $alv + ($uni / 30);
                $oldQtyAlv = (float) ($prod->{"grade_{$g}"} ?? 0);
                $delta     = round($newQtyAlv - $oldQtyAlv, 4);

                $newGrades["grade_{$g}"] = round($newQtyAlv, 4);

                if (abs($delta) > 0.0001) {
                    StockIntegrationService::syncMovement(
                        strtoupper($g),
                        'oeufs',
                        abs($delta),
                        $delta > 0 ? 'in' : 'out',
                        "Tri lot {$prod->batch->code} — calibre " . strtoupper($g),
                        'Alvéole'
                    );
                }
            }

            // ─── Synchronisation des pertes ───
            $lossMap = [
                'broken_eggs' => 'Cassé',
                'small_eggs'  => 'Anomalie',
            ];

            foreach ($lossMap as $field => $stockName) {
                $newVal    = (int) ($data[$field] ?? 0);
                $oldVal    = (int) ($prod->$field ?? 0);
                $deltaUnits = $newVal - $oldVal;
                $deltaAlv   = round($deltaUnits / 30, 4);

                if (abs($deltaAlv) > 0.0001) {
                    StockIntegrationService::syncMovement(
                        $stockName,
                        'oeufs',
                        abs($deltaAlv),
                        $deltaAlv > 0 ? 'in' : 'out',
                        "Ajustement pertes lot {$prod->batch->code}",
                        'Alvéole'
                    );
                }
            }

            // ─── Mise à jour de la production ───
            $prod->update(array_merge($newGrades, [
                'broken_eggs' => $data['broken_eggs'],
                'small_eggs'  => $data['small_eggs'],
                'is_graded'   => true,
            ]));

            return $prod->fresh();
        });
    }
}
