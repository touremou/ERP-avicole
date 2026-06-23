<?php

namespace App\Actions\Crop;

use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\Stock;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre une récolte sur un cycle de culture et, en option, l'intègre au
 * stock (catégorie « recoltes »).
 *
 * Bascule le cycle en statut « recolte » dès la première récolte saisie, pour
 * matérialiser l'entrée en phase de récolte (le passage à « termine » reste une
 * action explicite de clôture).
 */
class RecordHarvest
{
    /**
     * @param array{harvest_date:string, quantity:numeric, unit?:string,
     *              net_weight_kg?:numeric, loss_quantity?:numeric, quality?:string,
     *              employee_id?:int, unit_price?:numeric, notes?:string,
     *              sync_to_stock?:bool, stock_item_name?:string} $data
     */
    public function execute(CropCycle $cycle, array $data): Harvest
    {
        return DB::transaction(function () use ($cycle, $data) {
            $syncToStock = (bool) ($data['sync_to_stock'] ?? false);
            $stockItem   = trim((string) ($data['stock_item_name'] ?? $cycle->crop_name));
            $unit        = $data['unit'] ?? 'kg';
            $quantity    = (float) $data['quantity'];

            // Poids net pesé (toujours en kg). Si non fourni mais que la récolte
            // est saisie en kg, on le déduit de la quantité — les KPI de
            // rendement restent ainsi alimentés sans double saisie.
            $netWeightKg = isset($data['net_weight_kg']) && $data['net_weight_kg'] !== null && $data['net_weight_kg'] !== ''
                ? (float) $data['net_weight_kg']
                : (strtolower($unit) === 'kg' ? $quantity : null);

            $harvest = $cycle->harvests()->create([
                'farm_id'         => $cycle->farm_id,
                'employee_id'     => $data['employee_id'] ?? null,
                'harvest_date'    => $data['harvest_date'],
                'quantity'        => $quantity,
                'unit'            => $unit,
                'net_weight_kg'   => $netWeightKg,
                'loss_quantity'   => $data['loss_quantity'] ?? 0,
                'quality'         => $data['quality'] ?? Harvest::QUALITY_BON,
                'unit_price'      => $data['unit_price'] ?? null,
                'notes'           => $data['notes'] ?? null,
                'synced_to_stock' => false,
                'stock_item_name' => $syncToStock ? $stockItem : null,
            ]);

            // Première récolte → le cycle entre en phase de récolte.
            if ($cycle->status === CropCycle::STATUS_EN_COURS) {
                $cycle->update(['status' => CropCycle::STATUS_RECOLTE]);
            }

            // ─── Intégration stock optionnelle ───
            if ($syncToStock && $quantity > 0) {
                StockIntegrationService::ensureItem(Stock::CAT_RECOLTES, $stockItem, $unit, (float) ($data['unit_price'] ?? 0));

                $moved = StockIntegrationService::syncMovement(
                    itemName: $stockItem,
                    category: Stock::CAT_RECOLTES,
                    quantity: $quantity,
                    type: 'in',
                    notes: "Récolte cycle {$cycle->code} ({$cycle->crop_name})",
                    inputUnit: $unit,
                    unitCost: $data['unit_price'] ?? null,
                );

                if ($moved !== false) {
                    $harvest->update(['synced_to_stock' => true]);
                }
            }

            return $harvest->fresh();
        });
    }
}
