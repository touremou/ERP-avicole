<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;

class Incubator extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'name', 
        'capacity', 
        'status' // Disponible, Occupé, Maintenance, Panne
    ];

    protected $casts = [
        'capacity'   => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    public function incubations(): HasMany
    {
        return $this->hasMany(Incubation::class);
    }

    public function activeIncubation(): HasOne
    {
        return $this->hasOne(Incubation::class)->where('status', '!=', 'clos');
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(IncubatorMaintenance::class)->latest();
    }

    // -----------------------
    // ACCESSEURS (LOGIQUE MÉTIER)
    // -----------------------

    public function getGlobalSuccessRateAttribute(): float
    {
        $closedCycles = $this->incubations()->where('status', 'clos')->get();
        if ($closedCycles->isEmpty()) return 0.0;

        return round($closedCycles->avg('hatchability_rate'), 1);
    }

    public function getOccupancyRateAttribute(): float
    {
        $active = $this->activeIncubation;
        if (!$active || $this->capacity <= 0) return 0.0;

        return round(($active->eggs_count / $this->capacity) * 100, 1);
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'Disponible'  => 'emerald',
            'Occupé'      => 'blue',
            'Maintenance' => 'orange',
            'Panne'       => 'rose',
            default       => 'slate',
        };
    }

    // -----------------------
    // SCOPES DE FILTRAGE
    // -----------------------

    public function scopeAvailable($query)
    {
        return $query->where('status', 'Disponible');
    }

    public function scopeInProduction($query)
    {
        return $query->where('status', 'Occupé');
    }

    public function scopeNeedsMaintenance($query)
    {
        return $query->whereDoesntHave('maintenances', function($q) {
            $q->where('maintenance_date', '>', now()->subDays(90));
        });
    }
}