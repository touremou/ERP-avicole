<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use App\Traits\ReferencesEmployee;
use App\Traits\HasStandardUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CONTRÔLE d'un lot en conservation (T2) — la pesée périodique au magasin.
 *
 * On saisit ce qu'on MESURE (la pesée), jamais l'écart : la freinte s'en déduit.
 * Saisir directement une perte laisserait la porte ouverte à un chiffre arrondi
 * « au sentiment », et il n'y aurait plus rien à recouper.
 *
 * Le relevé du COURS DU MARCHÉ fait partie du contrôle : la personne qui va au
 * magasin est celle qui connaît le prix du jour. C'est ce qui rend le prix-cible
 * exploitable sans flux de cotation.
 */
class StoredLotCheck extends Model
{
    use HasFactory, HasStandardUuid, BelongsToFarm, ReferencesEmployee;

    /** État sanitaire constaté. */
    public const CONDITIONS = [
        'bon'         => 'Bon état',
        'humide'      => 'Reprise d\'humidité',
        'insectes'    => 'Attaque d\'insectes',
        'moisissure'  => 'Moisissure',
        'degrade'     => 'Dégradé / invendable',
    ];

    /** Décision prise à l'issue du contrôle. */
    public const ACTIONS = [
        'aucune'        => 'Aucune action',
        'sechage'       => 'Séchage complémentaire',
        'traitement'    => 'Traitement / assainissement',
        'reconditionne' => 'Reconditionnement',
        'declassement'  => 'Déclassement (vendu au rabais)',
        'destruction'   => 'Destruction / mise au rebut',
    ];

    /** États qui exigent une décision : laisser en l'état serait une négligence. */
    public const CONDITIONS_REQUIRING_ACTION = ['insectes', 'moisissure', 'degrade'];

    protected $fillable = [
        'uuid', 'farm_id', 'stored_lot_id', 'employee_id', 'recorded_by',
        'checked_at', 'weighed_quantity', 'shrinkage_quantity',
        'condition', 'action_taken', 'market_price', 'photo_path', 'notes',
    ];

    protected $casts = [
        'checked_at'         => 'datetime',
        'weighed_quantity'   => 'decimal:3',
        'shrinkage_quantity' => 'decimal:3',
        'market_price'       => 'decimal:2',
    ];

    public function storedLot(): BelongsTo { return $this->belongsTo(StoredLot::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }

    public function getConditionLabelAttribute(): string
    {
        return self::CONDITIONS[$this->condition] ?? ucfirst((string) $this->condition);
    }

    public function getActionLabelAttribute(): string
    {
        return self::ACTIONS[$this->action_taken] ?? ucfirst((string) $this->action_taken);
    }

    /** Le constat impose-t-il une décision (et non « aucune action ») ? */
    public function getNeedsActionAttribute(): bool
    {
        return in_array($this->condition, self::CONDITIONS_REQUIRING_ACTION, true);
    }
}
