<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class FeedPurchase extends Model
{
    use HasFactory, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'batch_id', 
        'purchase_date', 
        'feed_type', 
        'quantity', 
        'unit_price', 
        'total_price', 
        'supplier',
        'unit',
        'metadata' // Pour stocker le poids du sac, le type de conso, etc.
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'quantity' => 'decimal:3', // Précision au gramme pour les médicaments
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'metadata' => 'array',
    ];

    // --- RELATIONS ---

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    // --- LOGIQUE MÉTIER (ACCESSORS) ---

    /**
     * Retourne le facteur de conversion du sac (Défaut: 50kg)
     * Rigueur : On cherche d'abord dans les métadonnées de l'achat
     */
    public function getBagWeightAttribute(): int
    {
        return $this->metadata['bag_weight'] ?? 50;
    }

    /**
     * Quantité normalisée en KG ou Litre
     */
    public function getNormalizedQuantityAttribute(): float
    {
        if ($this->unit === 'Sac') {
            return (float) ($this->quantity * $this->bag_weight);
        }
        return (float) $this->quantity;
    }

    /**
     * Prix de revient réel par unité de mesure (KG ou L)
     * Vital pour le calcul de la rentabilité du lot
     */
    public function getRealPricePerUnitAttribute(): float
    {
        $totalQty = $this->normalized_quantity;
        if ($totalQty <= 0) return 0.0;

        return round((float) $this->total_price / $totalQty, 2);
    }

    /**
     * Libellé formaté pour les factures internes et rapports
     */
    public function getDisplayLabelAttribute(): string
    {
        $label = number_format($this->quantity, 1) . ' ' . ($this->unit ?? 'Units');
        if ($this->unit === 'Sac') {
            $label .= " (" . ($this->quantity * $this->bag_weight) . " KG)";
        }
        return $label;
    }

    /**
     * Détermine si cet achat est un aliment, un médicament ou du matériel
     */
    public function getCategoryAttribute(): string
    {
        return $this->metadata['conso_type'] ?? 'Aliment';
    }
}