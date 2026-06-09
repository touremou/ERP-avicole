<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use App\Services\StockIntegrationService;
use App\Traits\BelongsToFarm;

class MillProduction extends Model
{
    use HasFactory, BelongsToFarm;

    protected $table = 'mill_productions';

    protected $fillable = [
        'farm_id',
        'batch_number',
        'formula_id',
        'machine_id',
        'quantity_produced', // Stocké en KG
        'real_cost_per_kg',
        'operator_id',
        'supervisor_id',
        'started_at',
        'finished_at',
        'status' // Planifié, En cours, Terminé, Annulé
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'quantity_produced' => 'decimal:2',
        'real_cost_per_kg' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(MillMachine::class, 'machine_id');
    }

    /**
     * Relation avec la ligne de production complète (table pivot)
     */
    /*
    public function machines(): BelongsToMany
    {
        return $this->belongsToMany(
            MillMachine::class, 
            'mill_production_machine', 
            'mill_production_id', 
            'mill_machine_id'
        )->withTimestamps();
    } 
*/
    // Dans app/Models/MillProduction.php
    public function machines()
    {
        return $this->belongsToMany(MillMachine::class, 'mill_production_machine')
                    ->withPivot('snapshot_capacity_per_hour') // Ajout crucial pour la suite
                    ->withTimestamps();
    }
    // -----------------------
    // ACCESSEURS (KPI & UI)
    // -----------------------

    /**
     * Conversion dynamique en nombre de sacs (Standard 50kg)
     */
    public function getNbBagsAttribute(): float
    {
        return $this->quantity_produced > 0 ? round($this->quantity_produced / 50, 1) : 0;
    }

    /**
     * Durée réelle de la production en minutes
     */
    public function getDurationMinutesAttribute(): int
    {
        if (!$this->started_at || !$this->finished_at) return 0;
        return (int) $this->started_at->diffInMinutes($this->finished_at);
    }

    /**
     * Rendement horaire réel (kg/h) réalisé sur ce lot
     */
    public function getActualThroughputAttribute(): float
    {
        $hours = $this->duration_minutes / 60;
        if ($hours <= 0 || $this->quantity_produced <= 0) return 0.0;
        
        return round($this->quantity_produced / $hours, 2);
    }

    /**
     * Badge de statut stylisé pour Blade
     */
    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'Planifié' => 'bg-slate-100 text-slate-600',
            'En cours' => 'bg-blue-100 text-blue-600',
            'Terminé'  => 'bg-emerald-100 text-emerald-600',
            'Annulé'   => 'bg-red-100 text-red-600',
            default    => 'bg-gray-100 text-gray-600',
        };
    }

    // -----------------------
    // LOGIQUE MÉTIER
    // -----------------------

    /**
     * SCOPE : Filtrage par date de production
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', now()->toDateString());
    }

    /**
     * Méthode de secours pour finalisation manuelle
     * Rigueur : Centralisation du déstockage des MP et de l'entrée d'aliment fini
     */
    public function completeProduction(): bool
    {
        if ($this->status === 'Terminé') return false;

        return DB::transaction(function () {
            $this->load('formula.items.rawMaterial');

            // 1. Déstockage atomique des matières premières
            foreach ($this->formula->items as $item) {
                $quantityUsed = ($item->percentage / 100) * $this->quantity_produced;
                if ($item->rawMaterial) {
                    $item->rawMaterial->decrement('stock_qty', $quantityUsed);
                }
            }

            // 2. Entrée en Silo de l'aliment fini via le Service Global
            StockIntegrationService::syncMovement(
                $this->formula->name, 
                'conso', 
                $this->quantity_produced, 
                'in', 
                "Production OP #{$this->batch_number}",
                'KG'
            );

            // 3. Mise à jour de l'état de l'ordre de production
            return $this->update([
                'status' => 'Terminé',
                'finished_at' => now(),
            ]);
        });
    }
}