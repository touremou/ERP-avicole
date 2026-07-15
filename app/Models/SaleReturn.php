<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SaleReturn — avoir / retour client lié à une vente (restock + remboursement).
 */
class SaleReturn extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'sale_id', 'reference', 'return_date', 'reason',
        'total_refund', 'refund_method', 'user_id',
    ];

    protected $casts = [
        'return_date'  => 'date',
        'total_refund' => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
