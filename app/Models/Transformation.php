<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class Transformation extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id',
        'batch_number', 'product_source', 'transformation_type',
        'input_kg', 'output_kg', 'yield_percent',
        'production_date', 'expiry_date',
        'operator_id', 'production_cost', 'status', 'notes',
    ];

    protected $casts = [
        'input_kg'        => 'decimal:2',
        'output_kg'       => 'decimal:2',
        'yield_percent'   => 'decimal:2',
        'production_date' => 'date',
        'expiry_date'     => 'date',
        'production_cost' => 'decimal:2',
    ];

    public function operator(): BelongsTo { return $this->belongsTo(User::class, 'operator_id'); }

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
        $year = now()->format('Y');
        $last = static::where('batch_number', 'LIKE', "TRANS-{$year}-%")->max('batch_number');
        $seq = $last ? (int) substr($last, -6) + 1 : 1;
        return sprintf('TRANS-%s-%06d', $year, $seq);
    }
}
