<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\BelongsToFarm;

class StockMovement extends Model
{
    use HasFactory, BelongsToFarm;
    protected $fillable = [
        'uuid',
        'farm_id',
        'stock_id',
        'user_id',
        'type',               // in, out, adjustment, transfer
        'quantity',
        'unit_price',         
        'reference_type',     // Modèle lié (ex: App\Models\Production)
        'source_destination', // ID du modèle lié
        'notes'
    ];

    /**
     * Casts de données RÉALIGNÉS
     * Précision au gramme (15,3) pour les aliments et alvéoles.
     */
    protected $casts = [
        'quantity' => 'decimal:3', 
        'unit_price' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /**
     * RELATION : Vers l'article de stock parent
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * RELATION : Vers l'utilisateur (Opérateur ayant validé le flux)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * RELATION POLYMORPHIQUE (Traçabilité ERP)
     * Permet de remonter à la source : un Bon de Production, un Achat ou une Vente.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo(null, 'reference_type', 'source_destination');
    }

    /**
     * ACCESSEUR : Valeur monétaire du mouvement
     */
    public function getTotalValueAttribute(): float
    {
        return (float) ($this->quantity * ($this->unit_price ?? 0));
    }

    /**
     * HELPER : Badge de statut pour Blade
     * Centralise les couleurs et les icônes pour une UI cohérente.
     */
    public function getLabelAttribute(): array
    {
        return match($this->type) {
            'in' => [
                'text' => 'Entrée', 
                'color' => 'emerald', 
                'bg' => 'bg-emerald-100',
                'text_color' => 'text-emerald-700',
                'icon' => 'fa-arrow-down-long'
            ],
            'out' => [
                'text' => 'Sortie', 
                'color' => 'rose', 
                'bg' => 'bg-rose-100',
                'text_color' => 'text-rose-700',
                'icon' => 'fa-arrow-up-long'
            ],
            'adjustment' => [
                'text' => 'Ajustement', 
                'color' => 'amber', 
                'bg' => 'bg-amber-100',
                'text_color' => 'text-amber-700',
                'icon' => 'fa-sliders'
            ],
            'transfer' => [
                'text' => 'Transfert', 
                'color' => 'blue', 
                'bg' => 'bg-blue-100',
                'text_color' => 'text-blue-700',
                'icon' => 'fa-right-left'
            ],
            default => [
                'text' => strtoupper($this->type), 
                'color' => 'slate', 
                'bg' => 'bg-slate-100',
                'text_color' => 'text-slate-700',
                'icon' => 'fa-circle-dot'
            ],
        };
    }

    /**
     * HELPER : Formatage de la quantité avec unité
     * Utile pour les notifications ou les exports.
     */
    public function getFormattedQuantityAttribute(): string
    {
        // STM-02 corrigé : Le préfixe dépend strictement du type de flux, pas d'une quantité absolue.
        $prefix = $this->type === 'in' ? '+' : ($this->type === 'out' ? '-' : '');
        $unit = $this->stock->unit ?? '';
        
        return "{$prefix} " . number_format($this->quantity, 2) . " {$unit}";
    }
}