<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\BelongsToFarm;

class MillMachine extends Model
{
    use HasFactory, BelongsToFarm;

    protected $table = 'mill_machines';

    protected $fillable = [
        'farm_id',
        'name', 
        'type', 
        'capacity_per_hour', 
        'total_hours_run', 
        'last_maintenance',
        'maintenance_interval_hours', 
        'status'
    ];

    protected $casts = [
        'last_maintenance' => 'date',
        'total_hours_run' => 'float',
        'capacity_per_hour' => 'float',
        'maintenance_interval_hours' => 'integer',
        'created_at' => 'datetime',
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    /**
     * Productions où elle est enregistrée comme machine principale
     */
    public function productions(): HasMany
    {
        return $this->hasMany(MillProduction::class, 'machine_id');
    }

    /**
     * Productions en mode "Ligne de production" (Multi-machines via table pivot)
     */
    public function millProductions(): BelongsToMany
    {
        return $this->belongsToMany(
            MillProduction::class, 
            'mill_production_machine', 
            'mill_machine_id', 
            'mill_production_id'
        )->withTimestamps();
    }

    /**
     * Historique des logs de maintenance (SAV)
     */
    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class, 'mill_machine_id')->latest();
    }

    // -----------------------
    // ACCESSEURS (KPI PERFORMANCE & USURE)
    // -----------------------

    /**
     * Calcul du pourcentage d'usure avant la prochaine révision
     * 
     */
    public function getMaintenanceProgressAttribute(): float
    {
        if ($this->maintenance_interval_hours <= 0) return 0.0;
        
        $progress = ($this->total_hours_run / $this->maintenance_interval_hours) * 100;
        return (float) min(round($progress, 1), 100);
    }

    /**
     * Détermine si le seuil critique de maintenance est dépassé
     */
    public function getNeedsMaintenanceAttribute(): bool
    {
        return $this->maintenance_interval_hours > 0 
            && $this->total_hours_run >= $this->maintenance_interval_hours;
    }

    /**
     * Couleur du badge de statut pour l'interface UI AviSmart
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'Opérationnel' => 'emerald',
            'Maintenance'  => 'orange',
            'En Panne'     => 'rose',
            'Désactivé'    => 'slate',
            default        => 'gray',
        };
    }

    /**
     * Heures restantes avant la révision obligatoire
     */
    public function getHoursRemainingAttribute(): float
    {
        $remaining = $this->maintenance_interval_hours - $this->total_hours_run;
        return (float) max($remaining, 0);
    }

    // -----------------------
    // LOGIQUE MÉTIER
    // -----------------------

    /**
     * Vérifie la disponibilité opérationnelle
     */
    public function isAvailable(): bool
    {
        return $this->status === 'Opérationnel' && !$this->needs_maintenance;
    }

    /**
     * Rigueur ERP : Vérifie si la machine a un historique pour bloquer la suppression
     */
    public function hasProductionHistory(): bool
    {
        return $this->productions()->exists() || $this->millProductions()->exists();
    }
}