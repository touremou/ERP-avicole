<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\BelongsToFarm;

class DispatchItem extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'dispatch_id', 'product_type', 'product_name',
        'product_id', 'batch_id',
        'quantity_dispatched', 'unit', 'condition_at_dispatch',
    ];

    protected $casts = [
        'quantity_dispatched' => 'decimal:2',
    ];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function receptionItem(): HasOne
    {
        return $this->hasOne(ReceptionItem::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
