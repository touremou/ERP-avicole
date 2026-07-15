<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réception du vif (CCP 1) — contrôle ante-mortem à l'arrivée des volailles
 * vivantes : comptage, pesée, état sanitaire, respect de la diète, décision.
 *
 * IMMUABLE une fois validée (validated_at posé à la création) : aucune route
 * d'update/delete n'existe — le registre doit rester opposable (RG-06).
 * Une réception refusée ne peut donner lieu à aucun ordre d'abattage (RG-04).
 */
class SlaughterReception extends Model
{
    use BelongsToFarm;

    public const SANITARY_STATES = ['conforme', 'reserves', 'non_conforme'];
    public const FASTING = ['oui', 'non', 'partielle'];
    public const DECISIONS = ['accepte', 'accepte_avec_decote', 'refuse'];

    protected $fillable = [
        'farm_id', 'provider_id', 'reception_date', 'arrived_at',
        'announced_quantity', 'received_quantity', 'rejected_quantity',
        'total_live_weight_kg', 'sanitary_state', 'fasting_respected',
        'decision', 'decision_reason', 'doc_photo_path', 'controller_id',
        'releve_at', 'validated_at',
    ];

    protected $casts = [
        'reception_date'       => 'date',
        'arrived_at'           => 'datetime',
        'releve_at'            => 'datetime',
        'synced_at'            => 'datetime',
        'validated_at'         => 'datetime',
        'total_live_weight_kg' => 'decimal:2',
        'is_synced'            => 'boolean',
        'last_sync_at'         => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function controller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'controller_id');
    }

    public function slaughterOrders(): HasMany
    {
        return $this->hasMany(SlaughterOrder::class, 'reception_id');
    }

    public function isRefused(): bool
    {
        return $this->decision === 'refuse';
    }
}
