<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CropCalendarEvent extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    public const TYPES = [
        'traitement'  => 'Traitement',
        'observation' => 'Observation',
        'tache'       => 'Tâche',
        'rappel'      => 'Rappel',
        'autre'       => 'Autre',
    ];

    protected $fillable = [
        'uuid', 'farm_id', 'crop_cycle_id',
        'title', 'event_type', 'event_date', 'end_date',
        'notes', 'color',
    ];

    protected $casts = [
        'event_date' => 'date',
        'end_date'   => 'date',
    ];

    // ─── RELATIONS ───

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    // ─── SCOPES ───

    public function scopeForMonth($query, int $year, int $month)
    {
        return $query->whereYear('event_date', $year)
            ->whereMonth('event_date', $month);
    }

    // ─── ACCESSEURS ───

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->event_type] ?? $this->event_type;
    }
}
