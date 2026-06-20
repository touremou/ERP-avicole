<?php

namespace App\Actions\Crop;

use App\Models\CropTransformation;
use App\Models\Stock;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre une transformation végétale (entrée → sortie), calcule le rendement
 * et gère l'intégration stock :
 *  - déstockage optionnel de l'intrant (catégorie « recoltes ») ;
 *  - entrée optionnelle du produit fini (catégorie « produits_finis »).
 */
class RecordCropTransformation
{
    public function execute(array $data): CropTransformation
    {
        return DB::transaction(function () use ($data) {
            $input  = (float) $data['input_quantity'];
            $output = (float) $data['output_quantity'];
            $yield  = $input > 0 ? round($output / $input * 100, 2) : 0;

            $consumeFromStock = (bool) ($data['consumed_from_stock'] ?? false);
            $syncToStock      = (bool) ($data['synced_to_stock'] ?? false);
            $inputItem        = trim((string) ($data['input_stock_item'] ?? $data['input_product']));
            $outputItem       = trim((string) ($data['output_stock_item'] ?? $data['output_product']));

            $transformation = CropTransformation::create([
                'batch_number'        => CropTransformation::generateBatchNumber(),
                'crop_cycle_id'       => $data['crop_cycle_id'] ?? null,
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
                'production_cost'     => $data['production_cost'] ?? 0,
                'output_unit_price'   => $data['output_unit_price'] ?? null,
                'status'              => $data['status'] ?? CropTransformation::STATUS_TERMINE,
                'notes'               => $data['notes'] ?? null,
                'consumed_from_stock' => false,
                'synced_to_stock'     => false,
                'input_stock_item'    => $consumeFromStock ? $inputItem : null,
                'output_stock_item'   => $syncToStock ? $outputItem : null,
            ]);

            // ─── Déstockage de l'intrant (récolte consommée) ───
            if ($consumeFromStock && $input > 0) {
                $moved = StockIntegrationService::syncMovement(
                    itemName: $inputItem,
                    category: Stock::CAT_RECOLTES,
                    quantity: $input,
                    type: 'out',
                    notes: "Transformation {$transformation->batch_number} → {$transformation->output_product}",
                    inputUnit: $data['input_unit'] ?? 'kg',
                );

                if ($moved !== false) {
                    $transformation->update(['consumed_from_stock' => true]);
                }
            }

            // ─── Entrée du produit fini en stock ───
            if ($syncToStock && $output > 0) {
                $this->ensureStockItemExists($outputItem, $data['output_unit'] ?? 'kg', (float) ($data['output_unit_price'] ?? 0));

                StockIntegrationService::syncMovement(
                    itemName: $outputItem,
                    category: Stock::CAT_PRODUITS_FINIS,
                    quantity: $output,
                    type: 'in',
                    notes: "Transformation {$transformation->batch_number} ({$transformation->input_product})",
                    inputUnit: $data['output_unit'] ?? 'kg',
                    unitCost: $data['output_unit_price'] ?? null,
                );

                $transformation->update(['synced_to_stock' => true]);
            }

            return $transformation->fresh();
        });
    }

    private function ensureStockItemExists(string $itemName, string $unit, float $unitPrice): void
    {
        $exists = Stock::where('item_name', $itemName)
            ->where('category', Stock::CAT_PRODUITS_FINIS)
            ->exists();

        if ($exists) {
            return;
        }

        Stock::create([
            'category'         => Stock::CAT_PRODUITS_FINIS,
            'item_name'        => $itemName,
            'unit'             => $unit,
            'current_quantity' => 0,
            'unit_price'       => $unitPrice,
            'last_unit_price'  => $unitPrice,
            'alert_threshold'  => 0,
        ]);
    }
}
