<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groupe de prix de vente (tarif). Porte un prix par type de produit, utilisé
 * pour pré-remplir les lignes de vente selon le tarif rattaché au client.
 */
class SalePriceList extends Model
{
    use BelongsToFarm;

    protected $fillable = ['farm_id', 'name', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(SalePriceListItem::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'price_list_id');
    }

    /**
     * Prix de vente suggéré pour un client et un type de produit :
     * tarif du client en priorité, sinon tarif par défaut, sinon null
     * (l'opérateur saisit alors librement).
     */
    public static function suggestedPrice(?Client $client, string $productType): ?float
    {
        $listId = $client?->price_list_id
            ?? static::where('is_default', true)->value('id');

        if (! $listId) {
            return null;
        }

        $price = SalePriceListItem::where('sale_price_list_id', $listId)
            ->whereNull('product_id')
            ->where('product_type', $productType)
            ->value('unit_price');

        return $price !== null ? (float) $price : null;
    }

    /**
     * Prix suggéré pour un ARTICLE précis du catalogue. Cascade :
     *   1. prix par article du tarif du client,
     *   2. prix par catégorie du tarif du client,
     *   3. prix de base de l'article,
     *   sinon null.
     */
    public static function priceForProduct(?Client $client, Product $product): ?float
    {
        $listId = $client?->price_list_id
            ?? static::where('is_default', true)->value('id');

        if ($listId) {
            $perArticle = SalePriceListItem::where('sale_price_list_id', $listId)
                ->where('product_id', $product->id)
                ->value('unit_price');
            if ($perArticle !== null) {
                return (float) $perArticle;
            }

            $perType = static::suggestedPrice($client, $product->product_type);
            if ($perType !== null) {
                return $perType;
            }
        }

        return $product->base_price !== null ? (float) $product->base_price : null;
    }
}
