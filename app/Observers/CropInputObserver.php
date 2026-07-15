<?php

namespace App\Observers;

use App\Models\CropInput;
use App\Models\Stock;
use App\Services\StockIntegrationService;

/**
 * Réconcilie le STOCK « intrants » avec le registre des intrants d'un cycle,
 * indépendamment du chemin (contrôleur, action, import).
 *
 * RecordCropInput crée l'entrée stock à la saisie ; l'édition la corrige par
 * delta et la suppression la reverse — sinon le stock d'intrants dérivait /
 * double-comptait à chaque modification (bug audité, symétrique des récoltes).
 */
class CropInputObserver
{
    public function updated(CropInput $input): void
    {
        if (! $input->wasChanged(['quantity', 'unit', 'stock_item_name'])) {
            return;
        }

        $cat   = Stock::CAT_INTRANTS;
        $label = $input->cropCycle?->code ?? ('#' . $input->crop_cycle_id);

        if ($input->getOriginal('synced_to_stock')) {
            $oldName = trim((string) $input->getOriginal('stock_item_name'));
            $oldQty  = (float) $input->getOriginal('quantity');
            $oldUnit = $input->getOriginal('unit') ?: 'kg';
            if ($oldName !== '' && $oldQty > 0) {
                StockIntegrationService::syncMovement(
                    $oldName, $cat, $oldQty, 'out',
                    "Correction intrant {$label} (ancienne valeur annulée)", $oldUnit
                );
            }
        }

        if ($input->synced_to_stock) {
            $name = trim((string) $input->stock_item_name);
            $qty  = (float) $input->quantity;
            $unit = $input->unit ?: 'kg';
            if ($name !== '' && $qty > 0) {
                StockIntegrationService::ensureItem($cat, $name, $unit, (float) ($input->unit_cost ?? 0));
                StockIntegrationService::syncMovement(
                    $name, $cat, $qty, 'in',
                    "Correction intrant {$label} (nouvelle valeur)", $unit,
                    ($input->unit_cost ?? 0) > 0 ? (float) $input->unit_cost : null
                );
            }
        }
    }

    public function deleted(CropInput $input): void
    {
        if (! $input->synced_to_stock) {
            return;
        }

        $name = trim((string) $input->stock_item_name);
        $qty  = (float) $input->quantity;
        if ($name === '' || $qty <= 0) {
            return;
        }

        $label = $input->cropCycle?->code ?? ('#' . $input->crop_cycle_id);
        StockIntegrationService::syncMovement(
            $name, Stock::CAT_INTRANTS, $qty, 'out',
            "Annulation intrant supprimé {$label}", $input->unit ?: 'kg'
        );
    }
}
