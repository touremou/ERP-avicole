<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreasuryTransaction extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'treasury_account_id', 'direction', 'amount', 'transaction_date',
        'category', 'description', 'reference', 'counterpart_account_id', 'user_id',
        'source_type', 'source_id',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'transaction_date' => 'date',
    ];

    /** Pièce d'origine ayant généré l'écriture (Payment, Expense…). */
    public function source(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class, 'treasury_account_id');
    }

    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class, 'counterpart_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Montant signé (+ entrée / − sortie) pour l'affichage du grand-livre. */
    public function getSignedAmountAttribute(): float
    {
        return $this->direction === 'in' ? (float) $this->amount : -(float) $this->amount;
    }
}
