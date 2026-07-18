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

    /** Origine des sujets réceptionnés (le lot interne ne passe pas par ici). */
    public const ORIGINS = ['achat', 'facon'];

    /** Bases de tarification d'un ACHAT vif (mêmes bases connues à l'arrivée). */
    public const PURCHASE_BASES = [
        'par_sujet'  => 'Par sujet',
        'par_kg_vif' => 'Au kg vif',
        'forfait'    => 'Forfait',
    ];

    protected $fillable = [
        'farm_id', 'provider_id', 'origin', 'reception_date', 'arrived_at',
        'announced_quantity', 'received_quantity', 'rejected_quantity',
        'total_live_weight_kg', 'sanitary_state', 'fasting_respected',
        'decision', 'decision_reason', 'doc_photo_path', 'controller_id',
        'purchase_basis', 'purchase_unit_price', 'purchase_total_cost', 'supplier_invoice_id',
        'releve_at', 'validated_at',
    ];

    protected $casts = [
        'reception_date'       => 'date',
        'arrived_at'           => 'datetime',
        'releve_at'            => 'datetime',
        'synced_at'            => 'datetime',
        'validated_at'         => 'datetime',
        'total_live_weight_kg' => 'decimal:2',
        'purchase_unit_price'  => 'decimal:2',
        'purchase_total_cost'  => 'decimal:2',
        'is_synced'            => 'boolean',
        'last_sync_at'         => 'datetime',
    ];

    /**
     * Garde-fou anti-500 : la colonne `rejected_quantity` est NOT NULL (défaut
     * 0). Un client hors-ligne peut envoyer `null` quand aucun sujet n'est
     * écarté (0 écarté). On coalesce ici pour que TOUT appelant (web, sync,
     * futur) écrive 0 plutôt que de violer la contrainte à l'INSERT.
     */
    public function setRejectedQuantityAttribute($value): void
    {
        $this->attributes['rejected_quantity'] = $value ?? 0;
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    /** Achat vif (sujets achetés à l'éleveur) — par opposition au façon. */
    public function isPurchase(): bool
    {
        return $this->origin === 'achat';
    }

    /**
     * Coût d'achat calculé selon la base (null si pas un achat ou pas de prix).
     * Connu à l'arrivée : par sujet reçu, au kg vif pesé, ou forfait négocié.
     */
    public function computePurchaseCost(): ?float
    {
        if (! $this->isPurchase() || $this->purchase_unit_price === null) {
            return null;
        }

        $unit = (float) $this->purchase_unit_price;

        return round(match ($this->purchase_basis) {
            'par_sujet'  => (float) $this->received_quantity * $unit,
            'par_kg_vif' => (float) $this->total_live_weight_kg * $unit,
            'forfait'    => $unit,
            default      => 0.0,
        }, 2);
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
