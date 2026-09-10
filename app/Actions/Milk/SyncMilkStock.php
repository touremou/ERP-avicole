<?php

namespace App\Actions\Milk;

use App\Models\Stock;
use App\Services\StockIntegrationService;

/**
 * ENTRÉE DU LAIT AU MAGASIN — déclaration UNIQUE.
 *
 * Une traite collectée est un PRODUIT : elle doit être vendable. Cette
 * synchronisation vivait en privé dans `MilkProductionController`, donc sur le
 * seul chemin du bureau.
 *
 * Mesuré, sur une même collecte de 70 litres à 8 000 GNF :
 *
 *   • saisie au BUREAU  : 70 L au magasin, à 8 000 le litre ;
 *   • poussée par le TERRAIN : la production est enregistrée, et le magasin
 *     reste VIDE.
 *
 * Le lait trait au champ n'existait donc que comme statistique de production :
 * invendable, absent de la valeur d'inventaire, et invisible du magasinier qui
 * pourtant a les bidons devant lui. Or la traite est précisément le geste que
 * l'on saisit au troupeau, pas au bureau.
 *
 * Le prix suit la dernière collecte (le cours du lait est volatil) ; une
 * suppression ne touche pas au prix ($unitPrice null).
 */
class SyncMilkStock
{
    /**
     * @param  float  $delta      litres (positif = entrée, négatif = correction/sortie)
     * @param  string $batchCode  code du lot, pour la traçabilité du mouvement
     */
    public function execute(float $delta, string $batchCode, ?float $unitPrice = null): void
    {
        $stock = Stock::firstOrCreate(
            ['item_name' => 'Lait', 'category' => Stock::CAT_LAIT],
            [
                'unit'             => 'Litre',
                'current_quantity' => 0,
                'alert_threshold'  => (int) setting('stocks.default_alert_threshold', 0),
                'unit_price'       => $unitPrice ?? 0,
                'last_unit_price'  => $unitPrice ?? 0,
            ]
        );

        // Cohérence de valorisation : le prix du stock « Lait » suit le prix de
        // la dernière collecte (cours du lait volatil). On ne touche pas au prix
        // sur une suppression ($unitPrice null).
        if ($unitPrice !== null && $unitPrice > 0
            && (float) $stock->unit_price !== $unitPrice) {
            $stock->update(['unit_price' => $unitPrice, 'last_unit_price' => $unitPrice]);
        }

        if (abs($delta) < 0.001) {
            return;
        }

        StockIntegrationService::syncMovement(
            'Lait',
            Stock::CAT_LAIT,
            abs($delta),
            $delta > 0 ? 'in' : 'out',
            "Collecte lait — lot {$batchCode}",
            'Litre',
        );
    }
}
