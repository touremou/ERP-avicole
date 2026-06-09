<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class FormulaItem extends Model
{
    use HasFactory, BelongsToFarm;

     protected $casts = [
        'percentage' => 'decimal:2',
        'dosage_weight' => 'decimal:2',
        'quantity_kg' => 'decimal:2',
    ];

     public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

     public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }
    // app/Models/FormulaItem.php
    protected $fillable = [
        'farm_id',
        'formula_id',
        'raw_material_id',
        'percentage',
        'dosage_weight',
        'quantity_kg',
    ];
}
