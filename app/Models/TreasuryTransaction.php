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

    /**
     * Lien cliquable vers la pièce d'origine (rapprochement) : ['url','label']
     * ou null si la pièce n'est pas/plus liée ou la route absente.
     */
    public function getSourceLinkAttribute(): ?array
    {
        $s = $this->source;
        if (! $s) {
            return null;
        }
        $has = fn (string $r) => \Illuminate\Support\Facades\Route::has($r);

        if ($s instanceof \App\Models\Payment && $s->sale_id && $has('sales.show')) {
            return ['url' => route('sales.show', $s->sale_id), 'label' => __('Vente')];
        }
        if ($s instanceof \App\Models\Expense && $has('expenses.show')) {
            return ['url' => route('expenses.show', $s->id), 'label' => __('Dépense')];
        }
        if ($s instanceof \App\Models\SupplierPayment && $s->supplier_invoice_id && $has('purchases.show')) {
            return ['url' => route('purchases.show', $s->supplier_invoice_id), 'label' => __('Achat')];
        }

        return null;
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
