<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\BelongsToFarm;

class Stock extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'category',         // oeufs, conso, litieres, materiels
        'item_name',
        'feed_type',
        'unit',             // KG, Alvéole, Sac, Unité
        'current_quantity',
        'unit_price',
        'alert_threshold',
        'last_unit_price',
        'metadata'          // JSON: poultry_type, conso_type, supplier, bag_weight
    ];

    /**
     * Rigueur Industrielle : Précision au gramme (decimal:3) pour l'aliment
     * et précision monétaire (decimal:2).
     */
    protected $casts = [
        'metadata'         => 'array', 
        'current_quantity' => 'decimal:3',
        'alert_threshold'  => 'decimal:3',
        'unit_price'       => 'decimal:2',
        'last_unit_price'  => 'decimal:2',
        'created_at'       => 'datetime',
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest();
    }

    // -----------------------
    // ACCESSEURS (LOGIQUE MÉTIER & BI)
    // -----------------------

    /**
     * Valeur financière de l'inventaire.
     */
    public function getTotalValueAttribute(): float
    {
        return (float) ($this->current_quantity * ($this->last_unit_price ?? 0));
    }

    /**
     * Détermine si le seuil de sécurité est franchi.
     */
    public function getIsLowAttribute(): bool
    {
        return (float) $this->current_quantity <= (float) $this->alert_threshold;
    }

    /**
     * Traduction visuelle du stock d'œufs.
     * Conversion décimale -> Alvéoles (30 œufs/Alv).
     */
    public function getEggBreakdownAttribute(): array
    {
        if ($this->unit !== 'Alvéole') return [];

        $totalQty = (float) $this->current_quantity;
        $fullTrays = floor($totalQty);
        $remainingEggs = round(($totalQty - $fullTrays) * 30);

        return [
            'trays' => (int) $fullTrays,
            'eggs'  => (int) $remainingEggs,
            'label' => $fullTrays . ' Alv. + ' . $remainingEggs . ' œufs'
        ];
    }

    /**
     * Estimation du nombre de sacs restants (Standard 50kg).
     * Crucial pour l'inventaire physique du magasinier.
     */
    public function getSacksEstimateAttribute(): float
    {
        if ($this->unit !== 'KG' || $this->category !== 'conso') return 0;
        
        $bagWeight = $this->metadata['bag_weight'] ?? 50;
        return round((float) $this->current_quantity / $bagWeight, 1);
    }

    // -----------------------
    // SCOPES & HELPERS
    // -----------------------

    public function scopeCategory($query, $type)
    {
        return $query->where('category', $type);
    }

    /**
     * Accès sécurisé aux métadonnées JSON.
     */
    public function getMeta($key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Badge de couleur pour le Dashboard (AviSmart UI).
     */
    public function getStatusColorAttribute(): string
    {
        if ($this->current_quantity <= 0) return 'rose';
        if ($this->is_low) return 'orange';
        return 'emerald';
    }
}