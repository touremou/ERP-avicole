<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * License — abonnement activé sur l'instance (cf. App\Services\LicenseService).
 *
 * Modèle volontairement « plat » : les valeurs proviennent du jeton signé et
 * sont recopiées pour l'affichage (carte « Durée de validité ») et les
 * contrôles. La logique de statut/grâce vit dans LicenseService (source unique).
 */
class License extends Model
{
    protected $fillable = [
        'identifiant', 'client_name', 'plan', 'modules',
        'max_users', 'max_farms', 'sms_quota', 'sms_used',
        'fingerprint', 'issued_at', 'starts_at', 'expires_at',
        'activated_at', 'last_seen_at', 'revoked_at', 'last_online_check_at', 'token',
    ];

    protected $casts = [
        'modules'      => 'array',
        'max_users'    => 'integer',
        'max_farms'    => 'integer',
        'sms_quota'    => 'integer',
        'sms_used'     => 'integer',
        'issued_at'    => 'datetime',
        'starts_at'    => 'datetime',
        'expires_at'   => 'datetime',
        'activated_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at'   => 'datetime',
        'last_online_check_at' => 'datetime',
    ];

    /** Durée totale du contrat en jours (borne basse 1 pour éviter la division par zéro). */
    public function getTotalDaysAttribute(): int
    {
        $start = $this->starts_at ?? $this->issued_at ?? $this->created_at;

        return max(1, (int) $start->startOfDay()->diffInDays($this->expires_at->startOfDay()));
    }

    /** Jours écoulés depuis le début du contrat (plafonné au total). */
    public function getElapsedDaysAttribute(): int
    {
        $start = $this->starts_at ?? $this->issued_at ?? $this->created_at;
        $elapsed = (int) $start->startOfDay()->diffInDays(Carbon::today(), false);

        return max(0, min($this->total_days, $elapsed));
    }

    /** Jours restants avant expiration (0 si dépassé). */
    public function getDaysRemainingAttribute(): int
    {
        return max(0, (int) Carbon::today()->diffInDays($this->expires_at->startOfDay(), false));
    }

    /** SMS restants sur le quota. */
    public function getSmsRemainingAttribute(): int
    {
        return max(0, (int) $this->sms_quota - (int) $this->sms_used);
    }
}
