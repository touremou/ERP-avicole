<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ingrédient (intrant) d'une recette de transformation.
 */
class CropRecipeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'crop_recipe_id', 'input_product', 'quantity', 'unit', 'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(CropRecipe::class, 'crop_recipe_id');
    }
}
