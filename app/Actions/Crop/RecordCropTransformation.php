<?php

namespace App\Actions\Crop;

use App\Models\CropTransformation;
use App\Models\Harvest;
use App\Models\Stock;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre une transformation végétale (entrée → sortie), calcule le rendement
 * et gère l'intégration stock :
 *  - déstockage optionnel de l'intrant (catégorie « recoltes ») ;
 *  - entrée optionnelle du produit fini (catégorie « produits_finis »).
 *
 * COÛT DE REVIENT (T1) — le correctif central de cette action.
 *
 * Avant, le produit fini entrait en stock valorisé à `output_unit_price`, or ce
 * champ est le PRIX DE VENTE visé (le formulaire dit « Prix produit fini »).
 * Conséquences : inventaire gonflé, et marge ~0 le jour de la vente puisque le
 * CMP égalait le prix de vente. On ne pouvait donc pas prouver que sécher était
 * rentable — ce qui est précisément la question quand on transforme pour
 * reporter une vente.
 *
 * Désormais le stock est valorisé au coût de revient :
 *
 *     coût_revient/unité_sortie = (coût_matière + coût_opération) ÷ sortie
 *
 * Le coût matière est résolu par cascade (récolte → stock → cycle), jamais
 * inventé : à défaut il reste nul et l'absence est écrite dans les notes.
 */
class RecordCropTransformation
{
    public function execute(array $data): CropTransformation
    {
        return DB::transaction(function () use ($data) {
            $input  = (float) $data['input_quantity'];
            $output = (float) $data['output_quantity'];
            $yield  = $input > 0 ? round($output / $input * 100, 2) : 0;

            // Cohérence physique (audit C, même garde que l'abattoir) : une
            // transformation végétale perd de la matière (séchage, mouture,
            // pressage) — au-delà de ×1,5 c'est une erreur de pesée ou
            // d'unité (sortie saisie en unité ≠ entrée sans conversion).
            if ($input > 0 && $output > $input * 1.5
                && strtolower((string) ($data['output_unit'] ?? 'kg')) === strtolower((string) ($data['input_unit'] ?? 'kg'))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'output_quantity' => 'Rendement aberrant : ' . number_format($output, 1)
                        . ' produits pour ' . number_format($input, 1) . ' engagés ('
                        . round($output / $input * 100) . ' %). Vérifiez les deux pesées.',
                ]);
            }

            $consumeFromStock = (bool) ($data['consumed_from_stock'] ?? false);
            $syncToStock      = (bool) ($data['synced_to_stock'] ?? false);
            $inputItem        = trim((string) ($data['input_stock_item'] ?? $data['input_product']));
            $outputItem       = trim((string) ($data['output_stock_item'] ?? $data['output_product']));

            $harvest = ! empty($data['harvest_id']) ? Harvest::find($data['harvest_id']) : null;

            // ─── Coût de la matière première engagée ───
            [$inputCost, $costNote] = $this->resolveInputCost($data, $input, $inputItem, $consumeFromStock, $harvest);

            $productionCost = (float) ($data['production_cost'] ?? 0);
            // Coût de revient unitaire du produit fini : c'est LUI qui valorise
            // l'inventaire, et donc lui qui rend sincère la marge de vente.
            $outputUnitCost = $output > 0 ? round(($inputCost + $productionCost) / $output, 2) : null;

            $notes = trim((string) ($data['notes'] ?? ''));
            if ($costNote !== null) {
                $notes = $notes === '' ? $costNote : $notes . ' — ' . $costNote;
            }

            $transformation = CropTransformation::create([
                'batch_number'        => CropTransformation::generateBatchNumber(),
                'crop_cycle_id'       => $data['crop_cycle_id'] ?? $harvest?->crop_cycle_id,
                'harvest_id'          => $harvest?->id,
                'crop_recipe_id'      => $data['crop_recipe_id'] ?? null,
                'employee_id'         => $data['employee_id'] ?? null,
                'input_product'       => $data['input_product'],
                'output_product'      => $data['output_product'],
                'transformation_type' => $data['transformation_type'],
                'input_quantity'      => $input,
                'input_unit'          => $data['input_unit'] ?? 'kg',
                'output_quantity'     => $output,
                'output_unit'         => $data['output_unit'] ?? 'kg',
                'yield_percent'       => $yield,
                'production_date'     => $data['production_date'],
                'expiry_date'         => $data['expiry_date'] ?? null,
                'production_cost'     => $productionCost,
                'input_cost'          => round($inputCost, 2),
                'output_unit_cost'    => $outputUnitCost,
                'output_unit_price'   => $data['output_unit_price'] ?? null,
                'status'              => $data['status'] ?? CropTransformation::STATUS_TERMINE,
                'notes'               => $notes !== '' ? $notes : null,
                'consumed_from_stock' => false,
                'synced_to_stock'     => false,
                'input_stock_item'    => $consumeFromStock ? $inputItem : null,
                'output_stock_item'   => $syncToStock ? $outputItem : null,
            ]);

            // ─── Déstockage de l'intrant (récolte consommée) ───
            // strictOut (audit C) : la sortie est REFUSÉE si le stock est
            // insuffisant (contrôle sous verrou dans le service). Avant, le
            // plafonnement silencieux à zéro laissait passer une consommation
            // supérieure au disponible — matière fantôme non tracée. La
            // ValidationException annule toute la transaction (transformation
            // comprise).
            if ($consumeFromStock && $input > 0) {
                $moved = StockIntegrationService::syncMovement(
                    itemName: $inputItem,
                    category: Stock::CAT_RECOLTES,
                    quantity: $input,
                    type: 'out',
                    notes: "Transformation {$transformation->batch_number} → {$transformation->output_product}",
                    inputUnit: $data['input_unit'] ?? 'kg',
                    strictOut: true,
                );

                if ($moved !== false) {
                    $transformation->update(['consumed_from_stock' => true]);
                }
            }

            // ─── Entrée du produit fini en stock, AU COÛT DE REVIENT ───
            if ($syncToStock && $output > 0) {
                $unitCost = $outputUnitCost !== null && $outputUnitCost > 0 ? $outputUnitCost : null;

                StockIntegrationService::ensureItem(
                    Stock::CAT_PRODUITS_FINIS,
                    $outputItem,
                    $data['output_unit'] ?? 'kg',
                    (float) ($unitCost ?? 0),
                );

                StockIntegrationService::syncMovement(
                    itemName: $outputItem,
                    category: Stock::CAT_PRODUITS_FINIS,
                    quantity: $output,
                    type: 'in',
                    notes: "Transformation {$transformation->batch_number} ({$transformation->input_product})",
                    inputUnit: $data['output_unit'] ?? 'kg',
                    unitCost: $unitCost,
                );

                $transformation->update(['synced_to_stock' => true]);
            }

            return $transformation->fresh();
        });
    }

    /**
     * Coût de la matière première engagée, par cascade de sources FIABLES.
     *
     *  1. Coût fourni explicitement (import, reprise de saisie) — décision de
     *     l'opérateur, on la respecte.
     *  2. Récolte désignée → coût de production du cycle × quantité engagée.
     *     Source la plus juste : on sait exactement d'où vient la matière.
     *  3. Stock consommé → CMP de l'article (déjà valorisé au coût de production
     *     par RecordHarvest) × quantité engagée.
     *  4. Cycle désigné sans récolte précise → coût de production du cycle.
     *  5. Rien d'exploitable → 0, avec une note portée au registre. On préfère
     *     un coût manquant AVOUÉ à un coût inventé : le second se propagerait
     *     silencieusement dans la valeur d'inventaire et dans la marge.
     *
     * @return array{0: float, 1: string|null} [coût matière, note à joindre]
     */
    private function resolveInputCost(
        array $data,
        float $input,
        string $inputItem,
        bool $consumeFromStock,
        ?Harvest $harvest,
    ): array {
        if (isset($data['input_cost']) && $data['input_cost'] !== null && $data['input_cost'] !== '') {
            return [(float) $data['input_cost'], null];
        }

        if ($input <= 0) {
            return [0.0, null];
        }

        if ($harvest) {
            $perKg = $harvest->cropCycle?->productionCostPerKg() ?? 0.0;
            if ($perKg > 0) {
                return [$input * $perKg, null];
            }
        }

        if ($consumeFromStock && $inputItem !== '') {
            $cmp = (float) (Stock::where('item_name', $inputItem)
                ->where('category', Stock::CAT_RECOLTES)
                ->value('last_unit_price') ?? 0);
            if ($cmp > 0) {
                return [$input * $cmp, null];
            }
        }

        if (! empty($data['crop_cycle_id'])) {
            $perKg = \App\Models\CropCycle::find($data['crop_cycle_id'])?->productionCostPerKg() ?? 0.0;
            if ($perKg > 0) {
                return [$input * $perKg, null];
            }
        }

        return [
            0.0,
            'Coût matière non déterminé (ni récolte liée, ni stock valorisé, ni coût de cycle) '
                . '— le coût de revient du produit fini est incomplet.',
        ];
    }
}
