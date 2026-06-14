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

    // Taxonomie alignée sur SaleItem (multiespèces).
    public const STOCK_TYPES = ['oeufs', 'aliment', 'materiel'];
    public const BATCH_TYPES = ['animal_vif', 'carcasse', 'volaille_vivante', 'volaille_abattue'];
    public const COUNT_UNITS = ['tete', 'piece', 'unite'];

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
