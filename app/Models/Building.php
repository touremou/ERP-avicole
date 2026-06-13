<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use App\Traits\BelongsToFarm;

class Building extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'name', 
        'type', 
        'capacity', 
        'surface', 
        'description', 
        'status', // Vide, Occupé, En désinfection, Disponible, Maintenance
        'is_active',
        'disinfection_started_at' // Présent dans votre schéma DB
    ];

    protected $casts = [
        'capacity' => 'integer',
        'surface' => 'decimal:2',
        'is_active' => 'boolean',
        'disinfection_started_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // --- RELATIONS ---

    public function scopePhysical($query)
    {
        return $query->where('name', '!=', 'Zone Fournisseurs Externes');
    }

    /**
     * Un bâtiment contient plusieurs lots (historique et actuel)
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    // --- LOGIQUE MÉTIER (METHODS) ---

    /**
     * Sécurité : Empêcher le changement de vocation technique si une production est en cours
     */
    public function canChangeType(): bool
    {
        return !$this->batches()->active()->exists();
    }

    /**
     * Disponibilité réelle pour un nouveau lotissement
     */
    public function isAvailable(): bool
    {
        // Un bâtiment n'est disponible que s'il est marqué comme tel ET qu'il n'y a pas de lot actif
        return in_array($this->status, ['Vide', 'Disponible']) && !$this->batches()->active()->exists();
    }

    /**
     * Calcule le temps de repos restant (Vide Sanitaire)
     * Basé sur une norme industrielle standard de 14 jours
     */
    public function getSanitaryBreakRemainingDaysAttribute(): int
    {
        if ($this->status !== 'En désinfection' || !$this->disinfection_started_at) {
            return 0;
        }

        $targetDate = $this->disinfection_started_at->addDays(14);
        $remaining = now()->diffInDays($targetDate, false);

        return $remaining > 0 ? (int)$remaining : 0;
    }

    // --- ACCESSEURS (VIRTUAL ATTRIBUTES) ---

    /**
     * Densité actuelle (Sujets au m²)
     */
    public function getCurrentDensityAttribute(): float
    {
        $activeBatch = $this->batches()->active()->first();
        
        if (!$activeBatch || (float)$this->surface <= 0) {
            return 0.0;
        }

        return round($activeBatch->current_quantity / $this->surface, 2);
    }

    /**
     * Badge de couleur pour l'interface UI (AviSmart Design System)
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'Occupé'          => 'orange',
            'En désinfection' => 'purple',
            'Disponible', 'Vide' => 'emerald',
            'Maintenance'     => 'rose',
            default           => 'slate',
        };
    }

    /**
     * Taux d'occupation global (%) par rapport à la capacité théorique
     */
    public function getOccupancyRateAttribute(): float
    {
        $activeBatch = $this->batches()->active()->first();
        
        if (!$activeBatch || $this->capacity <= 0) {
            return 0.0;
        }

        return round(($activeBatch->current_quantity / $this->capacity) * 100, 1);
    }
}