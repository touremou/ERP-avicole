<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * Calcule automatiquement le manquant :
     * missing = dispatched - received - damaged
     */
    public function calculateMissing(): float
    {
        $dispatched = (float) $this->dispatchItem->quantity_dispatched;
        $received   = (float) $this->quantity_received;
        $damaged    = (float) $this->quantity_damaged;

        return max(0, $dispatched - $received - $damaged);
    }

    public function getHasDiscrepancyAttribute(): bool
    {
        return (float) $this->quantity_missing > 0;
    }
}
