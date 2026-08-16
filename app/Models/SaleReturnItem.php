<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    protected $fillable = [
        'sale_return_id', 'sale_item_id', 'product_name',
        // Instantanés : la ligne de vente est SUPPRIMÉE quand le retour est
        // total (sale_items n'a pas de suppression douce). Sans ces copies, le
        // chiffre retourné ne se rattache plus ni à une catégorie ni à un lot.
        'product_type', 'batch_id',
        'quantity', 'unit_price', 'total',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
