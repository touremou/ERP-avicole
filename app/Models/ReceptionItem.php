<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\Discrepancy\DiscrepancyEvaluator;
use App\Traits\BelongsToFarm;

class ReceptionItem extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'reception_id', 'dispatch_item_id',
        'quantity_received', 'quantity_damaged', 'quantity_missing',
        'condition_at_reception', 'notes',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:2',
        'quantity_damaged'  => 'decimal:2',
        'quantity_missing'  => 'decimal:2',
    ];

    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class);
    }

    public function dispatchItem(): BelongsTo
    {
        return $this->belongsTo(DispatchItem::class);
    }

    /**
     * Manquant calculé par le moteur d'écart (source unique) :
     * missing = max(0, expédié - reçu - endommagé)
     */
    public function calculateMissing(): float
    {
        return app(DiscrepancyEvaluator::class)->evaluateLine(
            $this->dispatchItem->product_type,
            (float) $this->dispatchItem->quantity_dispatched,
            (float) $this->quantity_received,
            (float) $this->quantity_damaged,
        )->missing;
    }

    /** Un écart existe si du manquant OU de l'endommagé est constaté. */
    public function getHasDiscrepancyAttribute(): bool
    {
        return (float) $this->quantity_missing > 0 || (float) $this->quantity_damaged > 0;
    }
}
