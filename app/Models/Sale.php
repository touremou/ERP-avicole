<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'client_id', 'user_id', 'sale_date',
        'type', 'status',
        'subtotal', 'tax_rate', 'tax_amount', 'total_amount',
        'paid_amount', 'payment_status',
        'delivery_mode', 'delivery_address', 'delivery_notes',
        'notes', 'validated_at', 'delivered_at',
    ];

    protected $casts = [
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

    // ─── ACCESSORS ───

    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->paid_amount);
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->payment_status === 'solde';
    }

    // ─── METHODS ───

    /**
     * Recalcule les totaux depuis les lignes.
     */
    public function recalculateTotals(): void
    {
        $subtotal = $this->items()->sum('total');
        $taxAmount = $this->tax_rate > 0 ? $subtotal * ($this->tax_rate / 100) : 0;

        $this->update([
            'subtotal'     => $subtotal,
            'tax_amount'   => $taxAmount,
            'total_amount' => $subtotal + $taxAmount,
        ]);
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
