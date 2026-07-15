<?php

namespace App\Services;

use App\Models\Client;
use App\Models\PriceList;
use App\Models\Stock;

/**
 * PricingService — résout le bon prix de vente selon la CATÉGORIE du client
 * (grossiste, détaillant…), en s'appuyant sur la grille tarifaire existante
 * (PriceList::getPrice, qui retombe sur le tarif « standard » si besoin).
 */
class PricingService
{
    /** Catégorie de client → palier tarifaire de la grille (PriceList.category). */
    public const TIER_BY_CLIENT_CATEGORY = [
        'grossiste'        => 'grossiste',
        'revendeur'        => 'grossiste',
        'hotel_restaurant' => 'grossiste',
        'detaillant'       => 'detaillant',
        'autre'            => 'standard',
    ];

    /** Paliers exposés (du grand public au gros). */
    public const TIERS = ['standard', 'detaillant', 'grossiste'];

    /** Palier tarifaire applicable à un client (comptoir/anonyme → détaillant). */
    public function tierForClient(?Client $client): string
    {
        if ($client === null) {
            return 'detaillant';
        }

        return self::TIER_BY_CLIENT_CATEGORY[$client->category] ?? 'detaillant';
    }

    /** Prix de vente d'un article de stock pour un palier donné (null si aucun). */
    public function priceForStock(Stock $stock, string $tier = 'standard'): ?float
    {
        $type = Stock::CATEGORY_TO_PRODUCT_TYPE[$stock->category] ?? null;
        if ($type === null) {
            return null;
        }

        return PriceList::getPrice($type, $stock->item_name, $tier);
    }

    /** Carte des prix d'un stock par palier : ['standard'=>, 'detaillant'=>, 'grossiste'=>]. */
    public function tierMapForStock(Stock $stock): array
    {
        $map = [];
        foreach (self::TIERS as $tier) {
            $map[$tier] = $this->priceForStock($stock, $tier);
        }

        return $map;
    }
}
