<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToFarm;

class Client extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'client_id', 'name', 'type', 'category', 'price_list_id',
        'phone', 'email', 'address',
        'nif', 'rccm',
        'credit_limit', 'balance',
        'status', 'notes',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'balance'      => 'decimal:2',
    ];

    // ─── RELATIONS ───

    public function priceList(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SalePriceList::class, 'price_list_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasManyThrough(Payment::class, Sale::class);
    }

    // ─── SCOPES ───

    public function scopeActive($query)
    {
        return $query->where('status', 'actif');
    }

    public function scopeWithDebt($query)
    {
        return $query->where('balance', '>', 0);
    }

    public function scopeOverCreditLimit($query)
    {
        return $query->whereRaw('balance > credit_limit AND credit_limit > 0');
    }

    // ─── ACCESSORS ───

    public function getIsOverLimitAttribute(): bool
    {
        return $this->credit_limit > 0 && $this->balance > $this->credit_limit;
    }

    public function getAvailableCreditAttribute(): float
    {
        if ($this->credit_limit <= 0) return 0;
        return max(0, $this->credit_limit - $this->balance);
    }

    // ─── METHODS ───

    /**
     * POURQUOI ce client ne peut pas prendre CE crédit — null s'il le peut.
     *
     * Règle unique du crédit client, et le seul frein sur l'argent qui sort de
     * l'exploitation à distance. Elle n'existait qu'en un endroit :
     * StoreSaleRequest, c'est-à-dire le formulaire du bureau.
     *
     *   • la SYNCHRO ne la connaissait pas — or c'est le canal des techniciens,
     *     ceux qui vendent réellement sur le terrain ;
     *   • la VALIDATION non plus — le moment où la créance naît, où le solde
     *     bouge et où la marchandise sort.
     *
     * Un client suspendu ou blacklisté pouvait donc être livré à crédit sans
     * plafond, pourvu que la vente ne passe pas par l'écran du bureau.
     *
     * @param float $newCredit Montant qui restera dû après le paiement immédiat.
     */
    public function creditRefusalReason(float $newCredit): ?string
    {
        if ($this->status !== 'actif') {
            return "Le client {$this->name} est {$this->status}.";
        }

        // Plafond à 0 = pas de plafond défini (convention historique du champ),
        // et une vente soldée d'avance ne crée aucun encours.
        if ($newCredit <= 0 || (float) $this->credit_limit <= 0) {
            return null;
        }

        if ((float) $this->balance + $newCredit > (float) $this->credit_limit) {
            return "Plafond crédit dépassé pour {$this->name}. "
                . "Solde actuel : " . number_format((float) $this->balance) . " GNF, "
                . "à créditer : " . number_format($newCredit) . " GNF, "
                . "Plafond : " . number_format((float) $this->credit_limit) . " GNF.";
        }

        return null;
    }

    /**
     * Recalcule le solde client depuis les ventes et paiements.
     */
    public function recalculateBalance(): void
    {
        $totalDue = $this->sales()
            ->whereNotIn('status', ['annule', 'brouillon'])
            ->sum('total_amount');

        $totalPaid = Payment::whereIn('sale_id', $this->sales()->pluck('id'))
            ->sum('amount');

        $this->update(['balance' => $totalDue - $totalPaid]);
    }
}
