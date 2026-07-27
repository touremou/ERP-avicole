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
     * TARIF APPLICABLE à un client — résolution unique.
     *
     * Elle était recopiée dans suggestedPrice() et dans priceForProduct().
     * Un tarif du client qui n'existe plus (liste supprimée) retombe sur le
     * tarif par défaut au lieu de rendre un barème vide, donc des prix de base
     * silencieux.
     */
    public static function resolveListFor(?Client $client): ?int
    {
        if ($client?->price_list_id && static::whereKey($client->price_list_id)->exists()) {
            return (int) $client->price_list_id;
        }

        $default = static::where('is_default', true)->value('id');

        return $default ? (int) $default : null;
    }

    /**
     * Prix de vente suggéré pour un client et un type de produit :
     * tarif du client en priorité, sinon tarif par défaut, sinon null
     * (l'opérateur saisit alors librement).
     */
    public static function suggestedPrice(?Client $client, string $productType): ?float
    {
        $listId = static::resolveListFor($client);

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
        $listId = static::resolveListFor($client);

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

    /**
     * D'OÙ VIENT LE PRIX — pendant web de priceOrigin() côté terrain.
     *
     * L'écran de vente affichait un prix sans jamais dire sa provenance ; c'est
     * ce silence qui a laissé une seconde grille tarifaire s'y glisser et écraser
     * le tarif négocié du client sans que personne le voie.
     *
     * @return array{price: ?float, source: string, label: string}
     */
    public static function explainPrice(?Client $client, ?Product $product, ?string $productType): array
    {
        $listId = static::resolveListFor($client);
        $listName = $listId ? static::whereKey($listId)->value('name') : null;

        if ($listId && $product) {
            $perArticle = SalePriceListItem::where('sale_price_list_id', $listId)
                ->where('product_id', $product->id)->value('unit_price');

            if ($perArticle !== null) {
                return ['price' => (float) $perArticle, 'source' => 'article',
                        'label' => __('Tarif :name (article)', ['name' => $listName])];
            }
        }

        $type = $productType ?: $product?->product_type;

        if ($listId && $type) {
            $perType = SalePriceListItem::where('sale_price_list_id', $listId)
                ->whereNull('product_id')->where('product_type', $type)->value('unit_price');

            if ($perType !== null) {
                return ['price' => (float) $perType, 'source' => 'categorie',
                        'label' => __('Tarif :name (catégorie)', ['name' => $listName])];
            }
        }

        if ($product && $product->base_price !== null) {
            return ['price' => (float) $product->base_price, 'source' => 'base',
                    'label' => __('Prix de base de l’article')];
        }

        return ['price' => null, 'source' => 'none', 'label' => __('Aucun tarif — à saisir')];
    }
}
