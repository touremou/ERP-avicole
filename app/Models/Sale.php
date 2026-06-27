<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasStandardUuid;
use App\Traits\BelongsToFarm;
use App\Traits\AuditsChanges;

class Sale extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm, AuditsChanges;

    protected $fillable = [
        'farm_id',
        'uuid', 'is_synced', 'last_sync_at',
        'reference', 'client_id', 'user_id', 'sale_date',
        'type', 'status',
        'subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'tax_rate', 'tax_amount', 'total_amount',
        'paid_amount', 'payment_status',
        'delivery_mode', 'delivery_address', 'delivery_notes',
        'notes', 'validated_at', 'delivered_at',
    ];

    protected $casts = [
        'is_synced'    => 'boolean',
        'last_sync_at' => 'datetime',
        'sale_date'    => 'date',
        'subtotal'     => 'decimal:2',
        'tax_rate'     => 'decimal:2',
        'tax_amount'   => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount'  => 'decimal:2',
        'validated_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    // ─── RELATIONS ───

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ─── SCOPES ───

    public function scopeUnpaid($query)
    {
        return $query->whereIn('payment_status', ['impaye', 'partiel']);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('sale_date', today());
    }

    public function scopeValidated($query)
    {
        return $query->whereIn('status', ['valide', 'livre']);
    }

    /**
     * Ventes en RETARD de paiement : impayées et dont l'échéance
     * (date de vente + délai de paiement paramétré) est dépassée.
     */
    public function scopeOverdue($query, ?int $delayDays = null)
    {
        $delayDays = $delayDays ?? (int) setting('ventes.payment_delay_days', 30);

        return $query->unpaid()
            ->whereDate('sale_date', '<=', today()->subDays($delayDays));
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(PaymentReminder::class);
    }

    // ─── ACCESSORS ───

    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->paid_amount);
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->payment_status === 'solde';
    }

    /** Échéance = date de vente + délai de paiement paramétré. */
    public function getDueDateAttribute(): \Illuminate\Support\Carbon
    {
        return $this->sale_date->copy()->addDays((int) setting('ventes.payment_delay_days', 30));
    }

    /** Jours de retard (positif si échéance dépassée, 0 sinon). */
    public function getDaysOverdueAttribute(): int
    {
        return max(0, (int) $this->due_date->startOfDay()->diffInDays(today(), false));
    }

    // ─── METHODS ───

    /**
     * Recalcule les totaux depuis les lignes.
     */
    public function recalculateTotals(): void
    {
        $subtotal = round((float) $this->items()->sum('total'), 2);

        // Remise globale appliquée au sous-total (jamais > sous-total).
        $discount = $this->computeDiscount($subtotal);
        $net = max(0, round($subtotal - $discount, 2));

        // La TVA porte sur la base APRÈS remise.
        $taxAmount = $this->tax_rate > 0 ? round($net * ($this->tax_rate / 100), 2) : 0;

        $this->update([
            'subtotal'        => $subtotal,
            'discount_amount' => $discount,
            'tax_amount'      => $taxAmount,
            'total_amount'    => round($net + $taxAmount, 2),
        ]);
    }

    /** Montant de remise effectif pour un sous-total donné. */
    public function computeDiscount(float $subtotal): float
    {
        $value = (float) $this->discount_value;
        if ($value <= 0) return 0.0;

        $discount = match ($this->discount_type) {
            'percent' => $subtotal * (min($value, 100) / 100),
            'amount'  => $value,
            default   => 0.0,
        };

        return round(min($discount, $subtotal), 2); // jamais négatif, ni > sous-total
    }

    /**
     * Met à jour le statut de paiement.
     */
    public function refreshPaymentStatus(): void
    {
        $totalPaid = $this->payments()->sum('amount');

        $status = match (true) {
            $totalPaid <= 0                          => 'impaye',
            $totalPaid >= (float) $this->total_amount => 'solde',
            default                                   => 'partiel',
        };

        $this->update([
            'paid_amount'    => $totalPaid,
            'payment_status' => $status,
        ]);
    }
}
