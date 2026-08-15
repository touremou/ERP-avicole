<?php

namespace App\Actions\EggProduction;

use App\Models\EggProduction;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $this->assertNotUnderWithdrawal($prod);

        return DB::transaction(function () use ($prod, $data) {
            $grades = array_map('strtolower', EggProduction::gradeCodes());
            $newGrades = [];

            // ─── Synchronisation des calibres ───
            foreach ($grades as $g) {
                $alv = (int) ($data["grade_{$g}_alv"] ?? 0);
                $uni = (int) ($data["grade_{$g}_uni"] ?? 0);

                // Quantité en alvéoles (unité pivot)
                $newQtyAlv = $alv + ($uni / \App\Services\UnitConverter::eggsPerTray());
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
                $deltaAlv   = round($deltaUnits / \App\Services\UnitConverter::eggsPerTray(), 4);

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

    /**
     * DÉLAI D'ATTENTE (résidus médicamenteux) — sécurité alimentaire.
     *
     * Le tri est le SEUL passage des œufs vers le stock vendable : c'est donc
     * ici que la règle s'applique, et nulle part ailleurs. La collecte, elle,
     * reste libre — les œufs ont été pondus, le registre doit le dire ; ce
     * qu'on interdit c'est de les mettre en vente.
     *
     * La règle existait déjà pour la viande (SlaughterService, blocage dur) et
     * la documentation du modèle l'annonçait pour « la viande/les œufs ». Côté
     * œufs elle n'avait aucun lecteur : une ponte prélevée en plein traitement
     * partait au calibrage puis à la vente sans que rien ne s'y oppose.
     *
     * On lit le délai à la DATE DE PONTE, pas à celle du tri : trier trois
     * jours plus tard ne rend pas les œufs consommables.
     */
    private function assertNotUnderWithdrawal(EggProduction $prod): void
    {
        $withdrawal = $prod->batch?->withdrawalOn($prod->production_date);

        if (! $withdrawal) {
            return;
        }

        throw ValidationException::withMessages(['logic' => sprintf(
            "Ponte du %s sous DÉLAI D'ATTENTE : « %s » administré le %s, échéance le %s. "
            . "Ces œufs ne peuvent pas entrer en stock vendable — ils doivent être écartés de la consommation. "
            . "La collecte reste enregistrée telle quelle.",
            $prod->production_date->format('d/m/Y'),
            $withdrawal->product_name,
            $withdrawal->intervention_date->format('d/m/Y'),
            $withdrawal->withdrawal_until->format('d/m/Y'),
        )]);
    }
}
