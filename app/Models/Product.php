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
        'farm_id', 'name', 'sku', 'product_type', 'unit',
        'base_price', 'photo_path', 'is_active', 'notes',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'is_active'  => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** URL de la photo (ou null), servie via le helper média robuste. */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? media_url($this->photo_path) : null;
    }
}
