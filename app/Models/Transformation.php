<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class Transformation extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'slaughter_order_id',
        'batch_number', 'product_source', 'transformation_type',
        'input_kg', 'output_kg', 'yield_percent',
        'production_date', 'expiry_date',
        'operator_id', 'production_cost', 'source_unit_cost', 'status', 'notes',
    ];

    protected $casts = [
        'input_kg'        => 'decimal:2',
        'output_kg'       => 'decimal:2',
        'yield_percent'   => 'decimal:2',
        'production_date' => 'date',
        'expiry_date'     => 'date',
        'production_cost' => 'decimal:2',
        'source_unit_cost' => 'decimal:2',
    ];

    public function operator(): BelongsTo { return $this->belongsTo(User::class, 'operator_id'); }

    /** Ordre d'abattage d'origine (traçabilité en cascade) — nullable. */
    public function slaughterOrder(): BelongsTo { return $this->belongsTo(SlaughterOrder::class); }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->transformation_type) {
            'fume'   => 'Fumé',
            'grille' => 'Grillé',
            'marine' => 'Mariné',
            default  => ucfirst($this->transformation_type),
        };
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public static function generateBatchNumber(): string
    {
        return \App\Services\DocumentNumberingService::generate('transformation');
    }
}
