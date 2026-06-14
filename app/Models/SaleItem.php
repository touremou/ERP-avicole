<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class SaleItem extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id',
        'sale_id', 'product_type', 'product_name',
        'product_id', 'batch_id',
        'quantity', 'unit', 'unit_price', 'total',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Types de lignes adossées au magasin (déstockage Stock).
     *
     * 'lait' (Stock::CAT_LAIT, alimenté par MilkProductionController) et
     * 'produits_finis' (Stock::CAT_PRODUITS_FINIS, alimenté par
     * SlaughterController::transferToStock et ChickDispatchController)
     * sont des articles physiques réels : ils doivent être sélectionnés
     * depuis le stock et décrémentés à la vente, pas saisis manuellement.
     */
    public const STOCK_TYPES = ['oeufs', 'lait', 'aliment', 'produits_finis', 'materiel'];

    /**
     * Types de lignes adossées à un lot d'animaux vivants (toute espèce).
     * `animal_vif`/`carcasse` sont génériques ; `volaille_vivante`/
     * `volaille_abattue` sont conservés pour la rétrocompatibilité.
     */
    public const BATCH_TYPES = ['animal_vif', 'carcasse', 'volaille_vivante', 'volaille_abattue'];

    /**
     * Unités exprimées en têtes/pièces (et non en poids/volume).
     * Seules ces unités permettent de décrémenter un effectif de lot.
     */
    public const COUNT_UNITS = ['tete', 'piece', 'unite'];

    /**
     * Détermine si cette ligne déstocke un article physique.
     */
    public function requiresDestock(): bool
    {
        return in_array($this->product_type, self::STOCK_TYPES);
    }

    /**
     * Détermine si cette ligne impacte un lot (animal vif, toute espèce).
     */
    public function impactsBatch(): bool
    {
        return in_array($this->product_type, self::BATCH_TYPES)
            && $this->batch_id !== null;
    }

    /**
     * Détermine si cette ligne doit décrémenter l'EFFECTIF du lot.
     *
     * On ne décrémente le compteur de têtes que pour les ventes exprimées
     * en têtes/pièces (ex. mouton vendu à la tête). Une vente au poids
     * (carcasse au kg, poisson au kg vif) ne dit rien du nombre d'animaux
     * retirés : on l'enregistre sans corrompre l'effectif — la biomasse
     * relève d'un suivi dédié (module Pisciculture/Biomasse).
     */
    public function decrementsBatchCount(): bool
    {
        return $this->impactsBatch() && in_array($this->unit, self::COUNT_UNITS);
    }
}
