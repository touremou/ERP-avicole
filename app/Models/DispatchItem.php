<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\BelongsToFarm;

class DispatchItem extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'dispatch_id', 'product_type', 'product_name',
        'product_id', 'batch_id',
        'quantity_dispatched', 'unit', 'condition_at_dispatch',
    ];

    protected $casts = [
        'quantity_dispatched' => 'decimal:2',
    ];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function receptionItem(): HasOne
    {
        return $this->hasOne(ReceptionItem::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    // Taxonomie alignée sur SaleItem (multiespèces) + catégories de stock
    // expédiables (litières, récoltes, intrants) — cf. Stock::CATEGORY_TO_PRODUCT_TYPE.
    public const STOCK_TYPES = ['oeufs', 'lait', 'aliment', 'produits_finis', 'materiel', 'litieres', 'recoltes', 'intrants'];
    public const BATCH_TYPES = ['animal_vif', 'carcasse', 'volaille_vivante', 'volaille_abattue'];
    public const COUNT_UNITS = ['tete', 'piece', 'unite'];

    /** Libellé canonique du type de produit (aligné sur SaleItem). */
    public function getTypeLabelAttribute(): string
    {
        return SaleItem::SELLABLE_TYPE_LABELS[$this->product_type] ?? match ($this->product_type) {
            'volaille_vivante' => 'Volaille vivante',
            'volaille_abattue' => 'Volaille abattue',
            'litieres'         => 'Litières',
            'recoltes'         => 'Récoltes',
            'intrants'         => 'Intrants',
            default            => ucfirst(str_replace('_', ' ', (string) $this->product_type)),
        };
    }

    /** Ligne adossée au magasin (déstockage Stock). */
    public function requiresDestock(): bool
    {
        return in_array($this->product_type, self::STOCK_TYPES);
    }

    /** Ligne adossée à un lot d'animaux vivants (toute espèce). */
    public function impactsBatch(): bool
    {
        return in_array($this->product_type, self::BATCH_TYPES) && $this->batch_id !== null;
    }

    /**
     * Décrémente l'EFFECTIF du lot uniquement pour les expéditions exprimées
     * en têtes/pièces. Une expédition au poids (carcasse au kg) n'indique pas
     * le nombre d'animaux retirés → on ne corrompt pas l'effectif.
     */
    public function decrementsBatchCount(): bool
    {
        return $this->impactsBatch() && in_array($this->unit, self::COUNT_UNITS);
    }
}
