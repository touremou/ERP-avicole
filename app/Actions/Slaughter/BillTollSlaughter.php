<?php

namespace App\Actions\Slaughter;

use App\Actions\Sale\CreateSale;
use App\Models\SlaughterOrder;
use Illuminate\Support\Facades\Log;

/**
 * Facturation de la prestation d'abattage à façon (E8) — appelée à la fin
 * de l'exécution d'un ordre `facon` :
 *
 *  1. calcule la prestation selon le modèle FIGÉ sur l'ordre
 *     (par_sujet × réels abattus | par_kg_vif × pesée réelle |
 *      par_kg_carcasse × pesée sortie), plancher = minimum forfaitaire ;
 *  2. génère une FACTURE BROUILLON dans le module Commerce (créances,
 *     encaissements, relances : tout l'existant s'applique) — la
 *     validation reste une décision humaine, comme toute vente.
 *
 * Best-effort : un échec de facturation ne doit JAMAIS annuler l'abattage
 * (le geste sanitaire est fait) — l'écart se voit au dossier de lot
 * (service_fee présent, service_sale_id absent) et se rattrape en ligne.
 */
class BillTollSlaughter
{
    public function execute(SlaughterOrder $order): SlaughterOrder
    {
        if (! $order->isFacon() || ! $order->billing_model) {
            return $order;
        }

        [$quantity, $unit] = match ($order->billing_model) {
            'par_sujet'       => [(float) $order->actual_quantity, 'sujet'],
            'par_kg_vif'      => [(float) $order->total_live_weight_kg, 'kg vif'],
            'par_kg_carcasse' => [(float) ($order->result?->total_carcass_weight_kg ?? 0), 'kg carcasse'],
            default           => [0.0, 'sujet'],
        };

        $rate = (float) $order->billing_rate;
        $minFee = (float) setting('abattoir.facon_min_fee', 0);
        $fee = max(round($quantity * $rate, 2), $minFee);

        $order->forceFill(['service_fee' => $fee])->save();

        try {
            // Si le forfait minimum s'applique, la ligne reste lisible :
            // 1 × forfait plutôt qu'un prix unitaire recalculé opaque.
            $belowMin = $quantity * $rate < $minFee;

            $sale = app(CreateSale::class)->execute([
                'client_id' => $order->client_id,
                'sale_date' => $order->actual_date?->toDateString() ?? now()->toDateString(),
                'type'      => 'facture',
                'notes'     => "Prestation d'abattage à façon — ordre {$order->order_number} "
                    . "({$order->actual_quantity} sujets, modèle : "
                    . (SlaughterOrder::BILLING_MODELS[$order->billing_model] ?? $order->billing_model) . ')',
                'items'     => [[
                    'product_type' => 'prestation',
                    'product_name' => "Abattage à façon — {$order->order_number}"
                        . ($belowMin ? ' (minimum forfaitaire)' : ''),
                    'quantity'     => $belowMin ? 1 : $quantity,
                    'unit'         => $belowMin ? 'forfait' : $unit,
                    'unit_price'   => $belowMin ? $minFee : $rate,
                ]],
            ]);

            $order->forceFill(['service_sale_id' => $sale->id])->save();

            Log::info("Façon {$order->order_number} : prestation {$fee} GNF → facture brouillon {$sale->reference}.");
        } catch (\Throwable $e) {
            Log::warning("Façon {$order->order_number} : facture non générée ({$e->getMessage()}) — prestation {$fee} GNF consignée sur l'ordre.");
        }

        return $order->fresh();
    }
}
