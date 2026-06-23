<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;

/**
 * Relevé météo & pluviométrie (module Production Végétale).
 */
class WeatherReading extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'farm_id', 'plot_id', 'reading_date',
        'temperature_min', 'temperature_max', 'humidity_pct',
        'rainfall_mm', 'wind_kmh', 'sunshine_h', 'notes',
    ];

    protected $casts = [
        'is_synced'       => 'boolean',
        'last_sync_at'    => 'datetime',
        'reading_date'    => 'date',
        'temperature_min' => 'decimal:1',
        'temperature_max' => 'decimal:1',
        'humidity_pct'    => 'decimal:1',
        'rainfall_mm'     => 'decimal:1',
        'wind_kmh'        => 'decimal:1',
        'sunshine_h'      => 'decimal:1',
    ];

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }

    /** Relevés d'un mois donné (format Y-m). */
    public function scopeForMonth($query, string $month)
    {
        [$y, $m] = array_pad(explode('-', $month), 2, null);
        if (! $y || ! $m) {
            return $query;
        }

        return $query->whereYear('reading_date', (int) $y)
            ->whereMonth('reading_date', (int) $m);
    }
}
