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
}
