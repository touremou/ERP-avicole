<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SupplierPayment — règlement d'un achat fournisseur (CRÉDIT, montant signé).
 *
 * Un avoir/remboursement fournisseur est un montant NÉGATIF. Le règlement solde
 * la dette ; il ne crée aucune dépense (le coût est déjà au registre via l'achat).
 */
class SupplierPayment extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'supplier_invoice_id', 'amount', 'payment_date',
        'method', 'treasury_account_id', 'reference', 'notes', 'paid_by',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'payment_date' => 'date',
    ];

    public const METHODS = [
        'especes'      => 'Espèces',
        'mobile_money' => 'Mobile Money (OM / MoMo)',
        'virement'     => 'Virement',
        'cheque'       => 'Chèque',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function getMethodLabelAttribute(): string
    {
        return self::METHODS[$this->method] ?? ucfirst((string) $this->method);
    }
}
