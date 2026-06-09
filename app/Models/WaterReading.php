<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class WaterReading extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'water_source_id', 'reading_date', 'user_id',
        'volume_consumed_liters', 'volume_added_liters',
        'quality_ph', 'chlorine_level', 'cost', 'notes',
    ];

    protected $casts = [
        'reading_date'           => 'date',
        'volume_consumed_liters' => 'decimal:2',
        'volume_added_liters'    => 'decimal:2',
        'quality_ph'             => 'decimal:2',
        'chlorine_level'         => 'decimal:2',
        'cost'                   => 'decimal:2',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(WaterSource::class, 'water_source_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getPhStatusAttribute(): string
    {
        if (! $this->quality_ph) return 'non_mesuré';
        if ($this->quality_ph >= 6.5 && $this->quality_ph <= 8.5) return 'optimal';
        if ($this->quality_ph >= 6.0 && $this->quality_ph <= 9.0) return 'acceptable';
        return 'hors_norme';
    }
}
