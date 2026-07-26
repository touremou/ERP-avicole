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
 *
 * DESTINATION (T1) : une récolte non vendue (à transformer, ou stockée pour
 * vendre plus cher plus tard) n'inscrit AUCUN revenu au cycle. En contrepartie
 * elle doit exister quelque part — donc elle entre obligatoirement en stock, et
 * elle exige une pesée en kg. Sans ces deux garde-fous, la matière conservée
 * serait sortie du revenu sans entrer nulle part : elle disparaîtrait.
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
        // DÉLAI AVANT RÉCOLTE (DAR) : après un traitement phytosanitaire, la
        // production n'est pas récoltable avant l'échéance de la notice
        // (résidus). Garde placée ICI = un seul point pour le web, la sync
        // mobile et tout appelant futur. Levée automatique à la date.
        // Garde évaluée à la DATE DE RÉCOLTE déclarée, non à l'instant de la
        // saisie : c'est la date de récolte qui décide de la présence de résidus.
        // Rend la reprise d'un historique possible sans exception au garde-fou.
        $harvestDate = ! empty($data['harvest_date']) ? \Carbon\Carbon::parse($data['harvest_date']) : now();

        if ($blocking = $cycle->activePreharvestInterval($harvestDate)) {
            throw new \Exception(sprintf(
                "Le cycle %s est sous délai avant récolte jusqu'au %s (%d j restants) suite au traitement « %s » — récolte interdite (résidus).",
                $cycle->code,
                $blocking->harvest_allowed_from->format('d/m/Y'),
                $blocking->preharvest_days_left,
                $blocking->name,
            ));
        }

        $destination = $data['destination'] ?? Harvest::DEST_VENTE;
        $isHeld      = in_array($destination, Harvest::DEST_HELD, true);

        $unit     = $data['unit'] ?? 'kg';
        $quantity = (float) $data['quantity'];

        // Poids net pesé (toujours en kg). Si non fourni mais que la récolte
        // est saisie en kg, on le déduit de la quantité — les KPI de
        // rendement restent ainsi alimentés sans double saisie.
        $netWeightKg = isset($data['net_weight_kg']) && $data['net_weight_kg'] !== null && $data['net_weight_kg'] !== ''
            ? (float) $data['net_weight_kg']
            : (strtolower($unit) === 'kg' ? $quantity : null);

        $effectiveKg = Harvest::effectiveWeightKgFrom($netWeightKg, $unit, $quantity);

        // PESÉE OBLIGATOIRE si la récolte est conservée. On ne peut pas valoriser
        // au coût, ni calculer un rendement de séchage, ni vendre plus tard un
        // stock dont on n'a jamais connu le poids. « 12 paniers » ne se sèche pas.
        if ($isHeld && $effectiveKg <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'net_weight_kg' => 'Une récolte ' . mb_strtolower(Harvest::DESTINATIONS[$destination])
                    . ' doit être pesée en kg : c\'est cette pesée qui la valorise en stock '
                    . 'et qui servira de base au rendement de transformation.',
            ]);
        }

        return DB::transaction(function () use ($cycle, $data, $destination, $isHeld, $unit, $quantity, $netWeightKg) {
            // Une récolte conservée entre TOUJOURS en stock : sortie du revenu,
            // elle doit être quelque part. Le choix n'est laissé qu'à la vente.
            $syncToStock = $isHeld ? true : (bool) ($data['sync_to_stock'] ?? false);
            $stockItem   = trim((string) ($data['stock_item_name'] ?? $cycle->crop_name));

            $harvest = $cycle->harvests()->create([
                'farm_id'         => $cycle->farm_id,
                'employee_id'     => $data['employee_id'] ?? null,
                'harvest_date'    => $data['harvest_date'],
                'quantity'        => $quantity,
                'unit'            => $unit,
                'net_weight_kg'   => $netWeightKg,
                'loss_quantity'   => $data['loss_quantity'] ?? 0,
                'quality'         => $data['quality'] ?? Harvest::QUALITY_BON,
                'destination'     => $destination,
                // Le prix n'a de sens que sur une vente. Sur une récolte
                // conservée on l'écarte : conservé, il serait tôt ou tard
                // resommé quelque part comme un revenu.
                'unit_price'      => $isHeld ? null : ($data['unit_price'] ?? null),
                'notes'           => $data['notes'] ?? null,
                'synced_to_stock' => false,
                'stock_item_name' => $syncToStock ? $stockItem : null,
            ]);

            // Première récolte → le cycle entre en phase de récolte.
            if ($cycle->status === CropCycle::STATUS_EN_COURS) {
                $cycle->update(['status' => CropCycle::STATUS_RECOLTE]);
            }

            // ─── Intégration stock optionnelle ───
            // L'inventaire « recoltes » est tenu en KG (poids net effectif) et
            // VALORISÉ AU COÛT DE PRODUCTION du cycle (et non au prix de vente,
            // qui surévaluerait l'inventaire). Une récolte sans poids effectif
            // (unité non-kg sans pesée) n'alimente pas le stock.
            $effectiveKg = $harvest->effective_weight_kg;
            if ($syncToStock && $effectiveKg > 0) {
                $costPerKg = $cycle->fresh()->productionCostPerKg();

                StockIntegrationService::ensureItem(Stock::CAT_RECOLTES, $stockItem, 'kg', $costPerKg);

                $moved = StockIntegrationService::syncMovement(
                    itemName: $stockItem,
                    category: Stock::CAT_RECOLTES,
                    quantity: $effectiveKg,
                    type: 'in',
                    notes: "Récolte cycle {$cycle->code} ({$cycle->crop_name})",
                    inputUnit: 'kg',
                    unitCost: $costPerKg > 0 ? $costPerKg : null,
                );

                if ($moved !== false) {
                    $harvest->update(['synced_to_stock' => true]);
                }
            }

            return $harvest->fresh();
        });
    }
}
