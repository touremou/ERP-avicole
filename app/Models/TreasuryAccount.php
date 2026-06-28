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
        'farm_id', 'name', 'type', 'default_for_method',
        'opening_balance', 'current_balance', 'is_active', 'notes',
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

    /** Type de compte cible pour un mode de paiement (vente ou dépense). */
    public static function typeForMethod(string $method): ?string
    {
        return match ($method) {
            'especes'                  => 'caisse',
            'orange_money', 'mobile_money' => 'mobile_money',
            'virement', 'cheque'       => 'banque',
            default                    => null,
        };
    }

    /**
     * Compte de trésorerie cible pour un mode de paiement :
     *   1. compte explicitement marqué « par défaut » pour ce mode ;
     *   2. sinon 1er compte actif du type correspondant (espèces→caisse…) ;
     *   3. sinon n'importe quel compte actif.
     * Renvoie null si aucun compte n'existe (la trésorerie reste optionnelle).
     */
    public static function resolveForMethod(string $method): ?self
    {
        // 1. Compte explicitement marqué pour ce mode précis (ex. « virement »).
        $flagged = static::active()->where('default_for_method', $method)->first();
        if ($flagged) {
            return $flagged;
        }

        if ($type = self::typeForMethod($method)) {
            // 2. Compte marqué pour le CANAL (ex. flag « mobile_money » sert aussi
            //    « orange_money » ; flag « caisse »/« banque » par type).
            $flaggedByChannel = static::active()
                ->whereIn('default_for_method', array_unique([$type, $method]))
                ->first();
            if ($flaggedByChannel) {
                return $flaggedByChannel;
            }

            // 3. 1er compte actif du type correspondant.
            $byType = static::active()->where('type', $type)->orderBy('id')->first();
            if ($byType) {
                return $byType;
            }
        }

        return static::active()->orderBy('id')->first();
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
