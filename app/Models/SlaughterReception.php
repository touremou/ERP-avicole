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
     *
     * ─── ON NE PAIE QUE CE QU'ON GARDE ───
     *
     * Ce calcul retenait `received_quantity` — les sujets ARRIVÉS — sans jamais
     * regarder ce que le contrôle ante-mortem en avait fait. Deux conséquences
     * mesurées, sur un achat à 20 000 GNF le sujet :
     *
     *   • 100 sujets REFUSÉS et renvoyés à l'éleveur : facture de 2 000 000 au
     *     lieu de zéro ;
     *   • 100 reçus dont 30 rejetés : facture de 2 000 000 au lieu de 1 400 000.
     *
     * Dans les deux cas l'exploitation facture des sujets qu'elle a RENDUS.
     * L'inspection ante-mortem sert précisément à refuser une marchandise ; la
     * payer quand même vide le contrôle de son effet économique.
     *
     * ─── LES TROIS BASES ───
     *
     * `par_sujet` et `par_kg_vif` sont proportionnelles : elles se prorate au
     * nombre RETENU. Le poids vif pesé porte sur le lot arrivé — faute de pesée
     * individuelle, on l'impute au prorata des têtes, ce qui est la meilleure
     * estimation disponible et non un chiffre inventé.
     *
     * `forfait` est un prix négocié pour la livraison : un rejet partiel ne le
     * découpe pas — cela se renégocie entre l'éleveur et l'exploitation. Un
     * refus TOTAL, lui, l'annule : il n'y a plus de livraison.
     */
    public function computePurchaseCost(): ?float
    {
        if (! $this->isPurchase() || $this->purchase_unit_price === null) {
            return null;
        }

        // Refus total : la marchandise repart, rien n'est dû.
        if ($this->isRefused()) {
            return 0.0;
        }

        $unit    = (float) $this->purchase_unit_price;
        $retenus = $this->acceptedQuantity();
        $recus   = max(0, (int) $this->received_quantity);

        // Prorata des têtes retenues, pour les bases proportionnelles.
        $part = $recus > 0 ? $retenus / $recus : 0.0;

        return round(match ($this->purchase_basis) {
            'par_sujet'  => (float) $retenus * $unit,
            'par_kg_vif' => (float) $this->total_live_weight_kg * $part * $unit,
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

    /** Sujets exploitables de la réception (reçus − écartés à l'ante-mortem). */
    public function acceptedQuantity(): int
    {
        return max(0, (int) $this->received_quantity - (int) $this->rejected_quantity);
    }

    /**
     * SOLDE DÉRIVÉ de la réception : sujets encore disponibles pour un ordre
     * d'abattage. Le registre étant IMMUABLE (RG-06), rien n'est décrémenté :
     * le solde se calcule depuis les ordres liés —
     *   restant = acceptés − Σ(exécutés en réel, en attente en planifié).
     * Un ordre ANNULÉ libère automatiquement son quota. $excludeOrderId permet
     * de re-valider un ordre donné sans compter sa propre demande deux fois.
     */
    public function remainingQuantity(?int $excludeOrderId = null): int
    {
        $consumed = $this->slaughterOrders()
            ->where('status', '!=', 'annule')
            ->when($excludeOrderId !== null, fn ($q) => $q->whereKeyNot($excludeOrderId))
            ->get(['id', 'status', 'planned_quantity', 'actual_quantity'])
            ->sum(fn (SlaughterOrder $order) => $order->status === 'termine'
                ? (int) $order->actual_quantity
                : (int) $order->planned_quantity);

        return max(0, $this->acceptedQuantity() - $consumed);
    }
}
