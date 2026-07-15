<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal d'ajustement / élimination d'un produit fini (abattoir).
 * Trace requêtable en base — pendant du registre de démarque du magasin.
 */
class FinishedProductAdjustment extends Model
{
    use BelongsToFarm;

    public const TYPE_ADJUSTMENT  = 'ajustement';
    public const TYPE_DISPOSAL    = 'elimination';

    protected $fillable = [
        'farm_id', 'finished_product_id', 'user_id',
        'type', 'old_kg', 'new_kg', 'reason',
    ];

    protected $casts = [
        'old_kg' => 'decimal:2',
        'new_kg' => 'decimal:2',
    ];

    public function finishedProduct(): BelongsTo { return $this->belongsTo(FinishedProduct::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** Écart signé (kg) introduit par l'écriture. */
    public function getDeltaKgAttribute(): float
    {
        return (float) $this->new_kg - (float) $this->old_kg;
    }
}
