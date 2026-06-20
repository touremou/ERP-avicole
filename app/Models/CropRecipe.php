<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;

/**
 * Recette de transformation (module Production Végétale).
 *
 * Standardise une opération d'agro-transformation (intrants, produit fini,
 * rendement de référence, conservation). Pendant végétal d'une `Formula`.
 */
class CropRecipe extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'farm_id', 'code', 'name', 'transformation_type',
        'output_product', 'output_unit', 'expected_yield_percent',
        'shelf_life_days', 'estimated_cost', 'is_active', 'notes',
    ];

    protected $casts = [
        'is_synced'              => 'boolean',
        'last_sync_at'           => 'datetime',
        'is_active'              => 'boolean',
        'shelf_life_days'        => 'integer',
        'expected_yield_percent' => 'decimal:2',
        'estimated_cost'         => 'decimal:2',
    ];

    // ─── RELATIONS ───

    public function items(): HasMany
    {
        return $this->hasMany(CropRecipeItem::class);
    }

    public function transformations(): HasMany
    {
        return $this->hasMany(CropTransformation::class);
    }

    // ─── SCOPES ───

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ─── ACCESSEURS ───

    public function getTypeLabelAttribute(): string
    {
        return CropTransformation::TYPES[$this->transformation_type]
            ?? ucfirst((string) $this->transformation_type);
    }

    /** Quantité totale d'intrants de la recette (référence pour un lot). */
    public function getTotalInputQuantityAttribute(): float
    {
        return (float) ($this->relationLoaded('items')
            ? $this->items->sum('quantity')
            : $this->items()->sum('quantity'));
    }
}
