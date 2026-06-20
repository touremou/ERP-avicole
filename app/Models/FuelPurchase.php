<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;
use App\Models\Building;

class FuelPurchase extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'energy_source_id', 'building_id', 'purchase_date', 'user_id',
        'quantity_liters', 'unit_price', 'total_cost',
        'supplier', 'receipt_reference',
        'fuel_level_after', 'notes',
    ];

    protected $casts = [
        'purchase_date'     => 'date',
        'quantity_liters'   => 'decimal:2',
        'unit_price'        => 'decimal:2',
        'total_cost'        => 'decimal:2',
        'fuel_level_after'  => 'decimal:2',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(EnergySource::class, 'energy_source_id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
