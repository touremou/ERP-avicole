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

    /**
     * Statuts opérationnels d'un bâtiment (valeurs stockées en base dans la
     * colonne `status`). Source unique de vérité référencée par les Actions
     * du module Lots (CreateBatch, CloseBatch, TransferBatch, UpdateBatch),
     * les services et commandes de vide sanitaire, et les vues `buildings/*`.
     *
     * ⚠️ Valeurs historiques (françaises, avec accents) : un renommage
     * casserait les enregistrements existants.
     */
    public const STATUS_VIDE         = 'Vide';
    public const STATUS_DISPONIBLE   = 'Disponible';
    public const STATUS_OCCUPE       = 'Occupé';
    public const STATUS_DESINFECTION = 'En désinfection';
    public const STATUS_MAINTENANCE  = 'Maintenance';

    /**
     * Statuts considérés comme « libres » : le bâtiment est prêt à accueillir
     * un nouveau lot (Vide et Disponible sont synonymes côté disponibilité).
     */
    public const STATUS_AVAILABLE = [
        self::STATUS_VIDE,
        self::STATUS_DISPONIBLE,
    ];

    /**
     * Durée standard du vide sanitaire (jours) avant réutilisation.
     */
    public const SANITARY_BREAK_DAYS = 14;

    protected $fillable = [
        'farm_id',
        'name',
        'type',
        'capacity',
        'surface',
        'description',
        'status', // cf. constantes STATUS_* ci-dessus
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

    // --- SCOPES ---

    /**
     * Bâtiments actuellement en vide sanitaire (désinfection en cours).
     */
    public function scopeInSanitaryBreak($query)
    {
        return $query->where('status', self::STATUS_DESINFECTION);
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
        return in_array($this->status, self::STATUS_AVAILABLE, true) && !$this->batches()->active()->exists();
    }

    /**
     * Indique si le bâtiment est en vide sanitaire.
     */
    public function isInSanitaryBreak(): bool
    {
        return $this->status === self::STATUS_DESINFECTION;
    }

    // --- TRANSITIONS D'ÉTAT (centralise la logique dispersée des Actions) ---

    /**
     * Marque le bâtiment comme occupé (un lot actif y est présent).
     */
    public function markOccupied(): void
    {
        $this->update(['status' => self::STATUS_OCCUPE]);
    }

    /**
     * Marque le bâtiment comme disponible (libéré, sans vide sanitaire).
     */
    public function markAvailable(): void
    {
        $this->update(['status' => self::STATUS_DISPONIBLE]);
    }

    /**
     * Déclenche le vide sanitaire : statut « En désinfection » et horodatage
     * du début, utilisé pour calculer le repos restant.
     */
    public function startSanitaryBreak(): void
    {
        $this->update([
            'status'                  => self::STATUS_DESINFECTION,
            'disinfection_started_at' => now(),
        ]);
    }

    /**
     * Calcule le temps de repos restant (Vide Sanitaire)
     * Basé sur une norme industrielle standard de 14 jours
     */
    public function getSanitaryBreakRemainingDaysAttribute(): int
    {
        if (! $this->isInSanitaryBreak() || !$this->disinfection_started_at) {
            return 0;
        }

        $targetDate = $this->disinfection_started_at->addDays(self::SANITARY_BREAK_DAYS);
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
            self::STATUS_OCCUPE                       => 'orange',
            self::STATUS_DESINFECTION                 => 'purple',
            self::STATUS_DISPONIBLE, self::STATUS_VIDE => 'emerald',
            self::STATUS_MAINTENANCE                  => 'rose',
            default                                   => 'slate',
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