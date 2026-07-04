<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;

/**
 * Article vendable du catalogue commercial.
 */
class Product extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'name', 'sku', 'product_type', 'stock_id', 'unit',
        'base_price', 'photo_path', 'is_active', 'is_favorite', 'notes',
    ];

    protected $casts = [
        'base_price'  => 'decimal:2',
        'is_active'   => 'boolean',
        'is_favorite' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Article de stock physique lié (optionnel) : sa vente décrémente ce stock. */
    public function stock(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /** Quantité disponible (du stock lié), ou null si l'article n'est pas suivi en stock. */
    public function getAvailableQuantityAttribute(): ?float
    {
        return $this->stock ? (float) $this->stock->current_quantity : null;
    }

    /** URL de la photo (ou null), servie via le helper média robuste. */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? media_url($this->photo_path) : null;
    }
}
