<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class SaleItem extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id',
        'sale_id', 'product_type', 'product_name',
        'product_id', 'batch_id',
        'quantity', 'unit', 'unit_price', 'total',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Détermine si cette ligne déstocke un article physique.
     */
    public function requiresDestock(): bool
    {
        return in_array($this->product_type, ['oeufs', 'aliment', 'materiel']);
    }

    /**
     * Détermine si cette ligne impacte un lot (volaille vivante).
     */
    public function impactsBatch(): bool
    {
        return in_array($this->product_type, ['volaille_vivante', 'volaille_abattue'])
            && $this->batch_id !== null;
    }
}
