<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;
use App\Models\Building;

class WaterReading extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'uuid',
        'farm_id', 'water_source_id', 'building_id', 'reading_date', 'user_id',
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

    protected static function booted(): void
    {
        // À la saisie d'un RELEVÉ (consommation), clôt la tâche « Relevé eau »
        // du jour. Un ravitaillement pur (appoint, consommation 0) n'est PAS un
        // relevé : il ne doit pas clôturer cette tâche.
        static::created(function (WaterReading $reading) {
            if ((float) $reading->volume_consumed_liters <= 0) return;

            app(\App\Services\ReleveTaskService::class)
                ->complete((int) $reading->farm_id, $reading->reading_date, 'releve_eau');
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(WaterSource::class, 'water_source_id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
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
