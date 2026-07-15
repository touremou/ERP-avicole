<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relevé de point critique HACCP (CCP 1 à 4) — INSERT-ONLY (RG-06).
 *
 * Un relevé validé ne se modifie JAMAIS : toute correction est une nouvelle
 * ligne portant corrects_record_id + corrected_by_id. C'est l'immutabilité
 * qui rend le registre opposable devant l'inspection vétérinaire.
 *
 * La conformité est calculée CÔTÉ SERVEUR selon les seuils du groupe de
 * réglages « abattoir » (jamais en dur — ajustables par le vétérinaire
 * conseil). Un CCP non conforme rattaché à un ordre le BLOQUE (RG-02).
 */
class CcpRecord extends Model
{
    use BelongsToFarm;

    public const CCP1 = 'ccp1_reception';
    public const CCP2 = 'ccp2_evisceration';
    public const CCP3 = 'ccp3_refroidissement';
    public const CCP4 = 'ccp4_chaine_froid';

    public const CCPS = [self::CCP1, self::CCP2, self::CCP3, self::CCP4];

    protected $fillable = [
        'farm_id', 'ccp', 'slaughter_order_id', 'equipment_ref',
        'mesures', 'conforme', 'corrective_action', 'operator_id',
        'releve_at', 'synced_at', 'corrected_by_id', 'corrects_record_id',
    ];

    protected $casts = [
        'mesures'   => 'array',
        'conforme'  => 'boolean',
        'releve_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function slaughterOrder(): BelongsTo
    {
        return $this->belongsTo(SlaughterOrder::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function correctsRecord(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_record_id');
    }

    public static function labelFor(string $ccp): string
    {
        return match ($ccp) {
            self::CCP1 => 'CCP 1 — Réception du vif',
            self::CCP2 => 'CCP 2 — Éviscération',
            self::CCP3 => 'CCP 3 — Refroidissement',
            self::CCP4 => 'CCP 4 — Chaîne du froid',
            default    => $ccp,
        };
    }
}
