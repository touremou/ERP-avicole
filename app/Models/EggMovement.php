<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class EggMovement extends Model
{
    use HasFactory, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'batch_id',
        'stock_id',
        'user_id',
        'type',          // in (Production/Retour), out (Vente/Don/Casse), adjustment
        'grade',         // S, M, L, XL, Cassé, Anomalie
        'quantity',      // Valeur brute
        'unit',          // Alvéole ou Unité
        'observations'
    ];

    protected $casts = [
        'quantity'   => 'decimal:3', // Alignement sur la précision du module Stock
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // -----------------------
    // RELATIONS VIA EAGER LOADING
    // -----------------------

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -----------------------
    // LOGIQUE MÉTIER & ACCESSEURS
    // -----------------------

    /**
     * Nombre d'œufs individuels (Brut)
     */
    public function getTotalEggsCountAttribute(): int
    {
        return ($this->unit === 'Alvéole') 
            ? (int)round($this->quantity * setting('general.eggs_per_tray', 30)) 
            : (int)$this->quantity;
    }

    /**
     * Quantité normalisée en Alvéoles pour la synchronisation des stocks
     */
    public function getQuantityInTraysAttribute(): float
    {
        return ($this->unit === 'Unité') 
            ? (float)($this->quantity / setting('general.eggs_per_tray', 30)) 
            : (float)$this->quantity;
    }

    /**
     * Détermine si le mouvement décrémente l'inventaire physique
     */
    public function isNegative(): bool
    {
        return in_array($this->type, ['out', 'casse_magasin', 'vente']);
    }

    // -----------------------
    // SCOPES DE FILTRAGE
    // -----------------------

    public function scopeSales($query)
    {
        return $query->where('type', 'vente');
    }

    public function scopeLosses($query)
    {
        return $query->whereIn('type', ['casse_magasin', 'perte']);
    }

    public function scopeByGrade($query, $grade)
    {
        return $query->where('grade', strtoupper($grade));
    }
}