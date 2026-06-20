<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;
use App\Models\Building;

class EnergyReading extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'energy_source_id', 'building_id', 'reading_date', 'user_id',
        'hours_run', 'fuel_consumed_liters', 'kwh_produced',
        'cost', 'outage_hours', 'notes',
    ];

    protected $casts = [
        'reading_date'         => 'date',
        'hours_run'            => 'decimal:2',
        'fuel_consumed_liters' => 'decimal:2',
        'kwh_produced'         => 'decimal:2',
        'cost'                 => 'decimal:2',
        'outage_hours'         => 'decimal:2',
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

    /**
     * Consommation spécifique (L/h) — KPI performance groupe.
     */
    public function getFuelRateAttribute(): ?float
    {
        if (! $this->fuel_consumed_liters || ! $this->hours_run || $this->hours_run <= 0) return null;
        return round($this->fuel_consumed_liters / $this->hours_run, 2);
    }
}
