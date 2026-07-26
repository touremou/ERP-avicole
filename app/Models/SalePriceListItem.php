<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePriceListItem extends Model
{
    protected $fillable = ['sale_price_list_id', 'product_id', 'product_type', 'unit_price'];

    protected $casts = ['unit_price' => 'decimal:2'];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(SalePriceList::class, 'sale_price_list_id');
    }

    /**
     * Lignes de tarif descendues au terrain (M6) — cloisonnement de ferme.
     *
     * La table ne porte PAS de farm_id : son périmètre est celui de sa liste de
     * prix (elle, bornée par BelongsToFarm). Sans ce filtre, le POS mobile
     * recevrait les tarifs des autres fermes.
     */
    public function scopeForFarmSync($query)
    {
        return $query->whereIn('sale_price_list_id', SalePriceList::query()->select('id'));
    }
}
