<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SupplierInvoice — Achat fournisseur (dette / compte à payer).
 *
 * DÉBIT du compte fournisseur. Réglé par des SupplierPayment (crédit signé).
 * À la validation, poste UNE dépense « valide » au registre Dépenses (mirror,
 * cf. FuelPurchase::syncLedgerExpense) : le coût entre au P&L une seule fois,
 * la dette résiduelle est suivie ici. Les paiements ne ré-imputent rien.
 */
class SupplierInvoice extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'provider_id', 'reference', 'invoice_date', 'due_date',
        'category', 'label', 'total_amount', 'status', 'expense_id', 'notes', 'user_id',
        'posts_expense', 'feed_purchase_id',
    ];

    protected $casts = [
        'invoice_date'  => 'date',
        'due_date'      => 'date',
        'total_amount'  => 'decimal:2',
        'posts_expense' => 'boolean',
    ];

    public const STATUSES = ['brouillon', 'valide', 'annule'];

    /** Catégories d'achat : on réutilise la taxonomie des dépenses. */
    public const CATEGORIES = Expense::CATEGORIES;

    // ─── RELATIONS ───

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function feedPurchase(): BelongsTo
    {
        return $this->belongsTo(FeedPurchase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── SCOPES ───

    public function scopeValidated($query)
    {
        return $query->where('status', 'valide');
    }

    /** Achats comptés dans la dette (tout sauf annulé). */
    public function scopeCounted($query)
    {
        return $query->where('status', '!=', 'annule');
    }

    // ─── ACCESSORS ───

    public function getPaidAmountAttribute(): float
    {
        return round((float) $this->payments->sum('amount'), 2);
    }

    public function getRemainingAmountAttribute(): float
    {
        return round(max(0, (float) $this->total_amount - $this->paid_amount), 2);
    }

    public function getPaymentStatusAttribute(): string
    {
        if ($this->status === 'brouillon') return 'brouillon';
        $paid = $this->paid_amount;
        if ($paid <= 0) return 'impaye';
        if ($paid + 0.001 < (float) $this->total_amount) return 'partiel';
        return 'solde';
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }

    public function getIsValidatedAttribute(): bool
    {
        return $this->status === 'valide';
    }

    // ─── MIRROR DÉPENSE (source unique P&L) ───

    /**
     * Crée (ou met à jour) la dépense « valide » liée à cet achat. Appelée à la
     * validation : le coût entre au registre Dépenses UNE fois. Même convention
     * que FuelPurchase : la dépense porte la référence de l'achat (traçabilité).
     */
    public function syncLedgerExpense(): ?Expense
    {
        // Achats à coût déjà compté ailleurs (ex. aliment → marge des lots) :
        // on ne poste AUCUNE dépense, pour éviter le double comptage.
        if (! $this->posts_expense) {
            return null;
        }

        $attributes = [
            'farm_id'       => $this->farm_id,
            'user_id'       => $this->user_id,
            'category'      => $this->category,
            'label'         => $this->label,
            'amount'        => $this->total_amount,
            'expense_date'  => $this->invoice_date,
            'status'        => 'valide',
            'supplier_name' => $this->provider?->name,
            'notes'         => 'Achat fournisseur ' . $this->reference,
        ];

        if ($this->expense) {
            $this->expense->update($attributes);

            return $this->expense;
        }

        $expense = Expense::create($attributes + ['reference' => $this->reference]);

        $this->expense_id = $expense->id;
        $this->saveQuietly();

        return $expense;
    }
}
