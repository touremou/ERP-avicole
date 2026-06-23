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
        'farm_id', 'name', 'type', 'brand', 'model', 'serial_number',
        'capacity_kva', 'fuel_type',
        'fuel_tank_capacity', 'current_fuel_level',
        'total_hours_run', 'maintenance_interval_hours',
        'last_maintenance_at', 'next_maintenance_at',
        'status', 'is_active', 'notes',
        'purchase_date', 'purchase_price', 'depreciation_years',
        'warranty_expiry', 'service_contract_ref',
    ];

    protected $casts = [
        'capacity_kva'              => 'decimal:2',
        'fuel_tank_capacity'        => 'decimal:2',
        'current_fuel_level'        => 'decimal:2',
        'total_hours_run'           => 'decimal:2',
        'last_maintenance_at'       => 'datetime',
        'next_maintenance_at'       => 'datetime',
        'is_active'                 => 'boolean',
        'purchase_date'             => 'date',
        'warranty_expiry'           => 'date',
        'purchase_price'            => 'decimal:0',
    ];

    public function readings(): HasMany
    {
        return $this->hasMany(EnergyReading::class);
    }

    public function fuelPurchases(): HasMany
    {
        return $this->hasMany(FuelPurchase::class);
    }

    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(AssetMaintenanceLog::class);
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

    /**
     * Autonomie gasoil en heures de fonctionnement (stock / conso horaire
     * moyenne sur les 7 derniers jours). Sert de comparaison directe au
     * seuil paramétrable `energie.autonomy_alert_hours`.
     */
    public function getFuelAutonomyHoursAttribute(): ?int
    {
        if ($this->type !== 'groupe' || ! $this->current_fuel_level) return null;

        $litersPerHour = $this->averageLitersPerHour();

        if (! $litersPerHour) return null;

        return (int) floor($this->current_fuel_level / $litersPerHour);
    }

    /**
     * Consommation horaire moyenne (L/h) sur les 7 derniers jours, avec repli
     * sur tout l'historique. Sert à la fois au calcul d'autonomie et à
     * l'estimation automatique du carburant consommé lors d'un relevé (pour
     * éviter la double saisie heures + litres).
     */
    public function averageLitersPerHour(): ?float
    {
        $compute = function ($query) {
            $rows = $query
                ->whereNotNull('fuel_consumed_liters')
                ->where('hours_run', '>', 0)
                ->get(['fuel_consumed_liters', 'hours_run']);

            $fuel  = $rows->sum('fuel_consumed_liters');
            $hours = $rows->sum('hours_run');

            return ($fuel > 0 && $hours > 0) ? $fuel / $hours : null;
        };

        // Priorité aux 7 derniers jours (régime récent), repli sur l'historique.
        return $compute($this->readings()->where('reading_date', '>=', now()->subDays(7)))
            ?? $compute($this->readings());
    }

    public function getNeedsMaintenanceAttribute(): bool
    {
        return $this->hours_before_maintenance <= 20;
    }

    /**
     * Gasoil critique : autonomie de fonctionnement (en heures) sous le
     * seuil paramétrable `energie.autonomy_alert_hours` (défaut 24h).
     * À défaut de conso horaire connue, repli sur l'autonomie en jours.
     */
    public function getIsFuelLowAttribute(): bool
    {
        if ($this->type !== 'groupe') return false;

        $alertHours = (float) setting('energie.autonomy_alert_hours', 24);

        if ($this->fuel_autonomy_hours !== null) {
            return $this->fuel_autonomy_hours <= $alertHours;
        }

        return ($this->fuel_autonomy_days ?? 99) <= ceil($alertHours / 24);
    }

    /**
     * Valeur résiduelle selon l'amortissement linéaire.
     */
    public function getResidualValueAttribute(): ?float
    {
        if (! $this->purchase_price || ! $this->purchase_date || ! $this->depreciation_years) {
            return null;
        }

        $yearsElapsed = $this->purchase_date->diffInDays(now()) / 365.25;
        $annualDepreciation = (float) $this->purchase_price / $this->depreciation_years;

        return max(0.0, (float) $this->purchase_price - ($annualDepreciation * $yearsElapsed));
    }

    /**
     * État de la garantie : active / expires_soon (≤30 j) / expired / unknown.
     */
    public function getWarrantyStatusAttribute(): string
    {
        if (! $this->warranty_expiry) return 'unknown';
        if ($this->warranty_expiry->isPast()) return 'expired';
        if (now()->diffInDays($this->warranty_expiry, false) <= 30) return 'expires_soon';

        return 'active';
    }

    /**
     * Coût total de maintenance (toutes interventions confondues).
     */
    public function getCumulativeMaintenanceCostAttribute(): float
    {
        return (float) $this->maintenanceLogs()->sum('cost');
    }
}
