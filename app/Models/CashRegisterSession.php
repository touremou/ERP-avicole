<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CashRegisterSession — session de caisse (ouverture → clôture).
 *
 * Théorique attendu en espèces = fond d'ouverture + encaissements espèces NETS
 * (remboursements déduits) effectués pendant la session. Le comptage des billets
 * à la clôture donne le réel ; l'écart = réel − théorique.
 */
class CashRegisterSession extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'user_id', 'treasury_account_id', 'status',
        'opened_at', 'opening_float', 'closed_at',
        'expected_cash', 'counted_cash', 'difference', 'denominations', 'notes',
    ];

    protected $casts = [
        'opened_at'     => 'datetime',
        'closed_at'     => 'datetime',
        'opening_float' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash'  => 'decimal:2',
        'difference'    => 'decimal:2',
        'denominations' => 'array',
    ];

    /** Coupures GNF proposées au comptage (de la plus grande à la plus petite). */
    public const DENOMINATIONS = [20000, 10000, 5000, 2000, 1000, 500, 100];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function treasuryAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Espèces théoriques actuellement en caisse = fond + encaissements espèces
     * NETS (remboursements négatifs inclus) depuis l'ouverture.
     */
    public function expectedCash(): float
    {
        $end = $this->closed_at ?? now();

        $netCash = (float) Payment::where('method', 'especes')
            ->whereBetween('created_at', [$this->opened_at, $end])
            ->sum('amount');

        return round((float) $this->opening_float + $netCash, 2);
    }
}
