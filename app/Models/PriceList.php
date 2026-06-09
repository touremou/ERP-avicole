<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToFarm;

class PriceList extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'product_type', 'product_name', 'category',
        'unit', 'unit_price', 'effective_date', 'is_active',
    ];

    protected $casts = [
        'unit_price'     => 'decimal:2',
        'effective_date' => 'date',
        'is_active'      => 'boolean',
    ];

    /**
     * Récupère le prix en vigueur pour un produit et une catégorie client.
     */
    public static function getPrice(string $productType, string $productName, string $category = 'standard'): ?float
    {
        $price = static::where('product_type', $productType)
            ->where('product_name', $productName)
            ->where('category', $category)
            ->where('is_active', true)
            ->orderByDesc('effective_date')
            ->value('unit_price');

        // Fallback sur le prix standard si pas de prix catégorie
        if ($price === null && $category !== 'standard') {
            $price = static::getPrice($productType, $productName, 'standard');
        }

        return $price ? (float) $price : null;
    }
}
