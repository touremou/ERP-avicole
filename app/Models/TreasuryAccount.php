<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TreasuryAccount — un canal de trésorerie (Caisse, Mobile Money, Banque…).
 *
 * Le solde est tenu sur `current_balance` (mis à jour à chaque mouvement) et
 * reste recalculable via recomputeBalance() pour garantir l'absence de dérive.
 */
class TreasuryAccount extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'name', 'type', 'opening_balance', 'current_balance', 'is_active', 'notes',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active'       => 'boolean',
    ];

    public const TYPES = [
        'caisse'       => 'Caisse (espèces)',
        'mobile_money' => 'Mobile Money (OM / MoMo)',
        'banque'       => 'Banque',
        'autre'        => 'Autre',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(TreasuryTransaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }

    public function getTypeIconAttribute(): string
    {
        return match ($this->type) {
            'caisse'       => 'fa-cash-register',
            'mobile_money' => 'fa-mobile-screen-button',
            'banque'       => 'fa-building-columns',
            default        => 'fa-wallet',
        };
    }

    /** Recalcule le solde depuis l'ouverture + le grand-livre (anti-dérive). */
    public function recomputeBalance(): void
    {
        $in  = (float) $this->transactions()->where('direction', 'in')->sum('amount');
        $out = (float) $this->transactions()->where('direction', 'out')->sum('amount');

        $this->update(['current_balance' => (float) $this->opening_balance + $in - $out]);
    }
}
