<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class CutProduct extends Model
{
    use BelongsToFarm;
    
    /** Conditionnements (UVC) d'une découpe. */
    public const PACKAGINGS = ['vrac', 'barquette', 'sachet'];

    protected $fillable = [
        'farm_id',
        'cutting_session_id', 'product_type', 'product_name',
        'quantity_kg', 'quantity_pieces', 'unit_price', 'unit_cost', 'destination',
        'calibre', 'packaging', 'pack_count',
    ];

    protected $casts = [
        'quantity_kg' => 'decimal:2',
        'unit_price'  => 'decimal:2',
        'unit_cost'   => 'decimal:2',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CuttingSession::class, 'cutting_session_id');
    }

    public function getTotalValueAttribute(): float
    {
        return (float) $this->quantity_kg * (float) $this->unit_price;
    }
}
