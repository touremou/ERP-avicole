<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;
use App\Traits\BelongsToFarm;

class EnergySource extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id', 'name', 'type', 'brand', 'model',
        'capacity_kva', 'fuel_type',
        'fuel_tank_capacity', 'current_fuel_level',
        'total_hours_run', 'maintenance_interval_hours',
        'last_maintenance_at', 'next_maintenance_at',
        'status', 'is_active', 'notes',
    ];

    protected $casts = [
        'capacity_kva'              => 'decimal:2',
        'fuel_tank_capacity'        => 'decimal:2',
        'current_fuel_level'        => 'decimal:2',
        'total_hours_run'           => 'decimal:2',
        'last_maintenance_at'       => 'datetime',
        'next_maintenance_at'       => 'datetime',
        'is_active'                 => 'boolean',
    ];

    public function readings(): HasMany
    {
        return $this->hasMany(EnergyReading::class);
    }

    public function fuelPurchases(): HasMany
    {
        return $this->hasMany(FuelPurchase::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeGroupes($query)
    {
        return $query->where('type', 'groupe')->where('is_active', true);
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'edg'     => 'EDG (Réseau)',
            'groupe'  => 'Groupe Électrogène',
            'solaire' => 'Solaire',
            default   => $this->type,
        };
    }

    /**
     * Heures restantes avant la prochaine maintenance.
     */
    public function getHoursBeforeMaintenanceAttribute(): float
    {
        if (! $this->last_maintenance_at) {
            return max(0, $this->maintenance_interval_hours - $this->total_hours_run);
        }

        $hoursSinceLastMaintenance = $this->readings()
            ->where('reading_date', '>=', $this->last_maintenance_at->toDateString())
            ->sum('hours_run');

        return max(0, $this->maintenance_interval_hours - $hoursSinceLastMaintenance);
    }

    /**
     * Autonomie gasoil en jours (basée sur la conso moyenne des 7 derniers jours).
     */
    public function getFuelAutonomyDaysAttribute(): ?int
    {
        if ($this->type !== 'groupe' || ! $this->current_fuel_level) return null;

        $avgDaily = $this->readings()
            ->where('reading_date', '>=', now()->subDays(7))
            ->whereNotNull('fuel_consumed_liters')
            ->avg('fuel_consumed_liters');

        if (! $avgDaily || $avgDaily <= 0) return 30; // Pas de conso = stock de sécurité

        return (int) floor($this->current_fuel_level / $avgDaily);
    }

    public function getNeedsMaintenanceAttribute(): bool
    {
        return $this->hours_before_maintenance <= 20;
    }

    public function getIsFuelLowAttribute(): bool
    {
        if ($this->type !== 'groupe') return false;
        return ($this->fuel_autonomy_days ?? 99) <= 3;
    }
}
