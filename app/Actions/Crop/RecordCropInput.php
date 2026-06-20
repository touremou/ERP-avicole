<?php

namespace App\Actions\Crop;

use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Stock;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre un intrant itémisé sur un cycle de culture et, en option,
 * l'intègre au stock (catégorie « intrants »).
 *
 * Le coût total est calculé (quantité × coût unitaire) si non fourni
 * explicitement, pour rester cohérent avec une saisie soit en ligne, soit
 * forfaitaire.
 */
class RecordCropInput
{
    public function execute(CropCycle $cycle, array $data): CropInput
    {
        return DB::transaction(function () use ($cycle, $data) {
            $quantity = (float) ($data['quantity'] ?? 0);
            $unitCost = (float) ($data['unit_cost'] ?? 0);
            $totalCost = isset($data['total_cost']) && $data['total_cost'] !== null
                ? (float) $data['total_cost']
                : round($quantity * $unitCost, 2);

            $syncToStock = (bool) ($data['synced_to_stock'] ?? false);
            $stockItem   = trim((string) ($data['stock_item_name'] ?? $data['name']));
            $unit        = $data['unit'] ?? 'kg';

            $input = $cycle->inputs()->create([
                'farm_id'         => $cycle->farm_id,
                'provider_id'     => $data['provider_id'] ?? null,
                'type'            => $data['type'] ?? 'autre',
                'name'            => $data['name'],
                'quantity'        => $quantity,
                'unit'            => $unit,
                'unit_cost'       => $unitCost,
                'total_cost'      => $totalCost,
                'input_date'      => $data['input_date'],
                'notes'           => $data['notes'] ?? null,
                'synced_to_stock' => false,
                'stock_item_name' => $syncToStock ? $stockItem : null,
            ]);

            // ─── Entrée stock optionnelle (achat d'intrant) ───
            if ($syncToStock && $quantity > 0) {
                $this->ensureStockItemExists($stockItem, $unit, $unitCost);

                StockIntegrationService::syncMovement(
                    itemName: $stockItem,
                    category: Stock::CAT_INTRANTS,
                    quantity: $quantity,
                    type: 'in',
                    notes: "Achat intrant — cycle {$cycle->code} ({$cycle->crop_name})",
                    inputUnit: $unit,
                    unitCost: $unitCost > 0 ? $unitCost : null,
                );

                $input->update(['synced_to_stock' => true]);
            }

            return $input->fresh();
        });
    }

    private function ensureStockItemExists(string $itemName, string $unit, float $unitPrice): void
    {
        $exists = Stock::where('item_name', $itemName)
            ->where('category', Stock::CAT_INTRANTS)
            ->exists();

        if ($exists) {
            return;
        }

        Stock::create([
            'category'         => Stock::CAT_INTRANTS,
            'item_name'        => $itemName,
            'unit'             => $unit,
            'current_quantity' => 0,
            'unit_price'       => $unitPrice,
            'last_unit_price'  => $unitPrice,
            'alert_threshold'  => 0,
        ]);
    }
}
