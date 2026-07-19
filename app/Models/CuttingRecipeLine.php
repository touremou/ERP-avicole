<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuttingRecipeLine extends Model
{
    public const TYPE_CO_PRODUIT   = 'co_produit';
    public const TYPE_SOUS_PRODUIT = 'sous_produit';
    public const TYPE_DECHET       = 'dechet';

    public const OUTPUT_TYPES = [
        self::TYPE_CO_PRODUIT   => 'Co-produit',
        self::TYPE_SOUS_PRODUIT => 'Sous-produit',
        self::TYPE_DECHET       => 'Déchet',
    ];

    protected $fillable = [
        'cutting_recipe_id', 'cut_code', 'label', 'output_type',
        'expected_yield_percent', 'value_coefficient',
        'default_destination', 'default_packaging', 'default_calibre',
        'is_default', 'sort_order',
    ];

    protected $casts = [
        'expected_yield_percent' => 'decimal:2',
        'value_coefficient'      => 'decimal:2',
        'is_default'             => 'boolean',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(CuttingRecipe::class, 'cutting_recipe_id');
    }
}
