<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class FeedPurchase extends Model
{
    use HasFactory, BelongsToFarm;

    /**
     * UNITÉS D'ACHAT — une déclaration, lue par la modale « Achat direct », la
     * requête web et la synchronisation terrain.
     *
     * La modale proposait « Boîte », « Flacon », « Pcs », « Paquet » ; le
     * serveur n'acceptait que « Sac, KG, Litre, Unité, Boite ». Un achat de
     * vaccin en boîtes était refusé — et, l'erreur restant dans la modale
     * fermée, la saisie disparaissait sans un mot.
     */
    public const UNITES = ['Sac', 'KG', 'Litre', 'Unité', 'Boite', 'Flacon', 'Paquet'];

    /** Catégories de stock qu'un achat direct peut alimenter. */
    public const CATEGORIES = ['conso', 'litieres', 'materiels'];

    /** Nature d'un consommable (catégorie « conso »). */
    public const NATURES = ['Aliment', 'Santé', 'Hygiène'];

    protected $fillable = [
        'farm_id',
        'uuid',
        'batch_id', 
        'purchase_date', 
        'feed_type', 
        'quantity', 
        'unit_price', 
        'total_price', 
        'supplier',
        'unit',
        'metadata' // Pour stocker le poids du sac, le type de conso, etc.
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'quantity' => 'decimal:3', // Précision au gramme pour les médicaments
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'metadata' => 'array',
    ];

    // --- RELATIONS ---

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class)->withTrashed();
    }

    // --- LOGIQUE MÉTIER (ACCESSORS) ---

    /**
     * Retourne le facteur de conversion du sac. Surcharge ponctuelle via les
     * métadonnées de l'achat, sinon poids du sac configuré (Réglages).
     */
    public function getBagWeightAttribute(): int
    {
        return (int) \App\Services\UnitConverter::bagWeight($this->metadata['bag_weight'] ?? null);
    }

    /**
     * Quantité normalisée en KG ou Litre
     */
    public function getNormalizedQuantityAttribute(): float
    {
        if (\App\Services\UnitConverter::isSac($this->unit)) {   // la règle de l'entrée en stock
            return \App\Services\UnitConverter::sacksToKg((float) $this->quantity, $this->metadata['bag_weight'] ?? null);
        }
        return (float) $this->quantity;
    }

    /**
     * Prix de revient réel par unité de mesure (KG ou L)
     * Vital pour le calcul de la rentabilité du lot
     */
    public function getRealPricePerUnitAttribute(): float
    {
        $totalQty = $this->normalized_quantity;
        if ($totalQty <= 0) return 0.0;

        return round((float) $this->total_price / $totalQty, 2);
    }

    /**
     * Libellé formaté pour les factures internes et rapports
     */
    public function getDisplayLabelAttribute(): string
    {
        $label = number_format($this->quantity, 1) . ' ' . ($this->unit ?? 'Units');
        if (\App\Services\UnitConverter::isSac($this->unit)) {   // la règle de l'entrée en stock
            $label .= " (" . ($this->quantity * $this->bag_weight) . " KG)";
        }
        return $label;
    }

    /** Nature d'un achat hors consommable (catégorie de stock « litieres » / « materiels »). */
    public const NATURE_LITIERE  = 'Litière';
    public const NATURE_MATERIEL = 'Matériel';

    /**
     * NATURE DE L'ACHAT — Aliment, Santé, Hygiène, Litière ou Matériel.
     *
     * Elle ne lisait que la nature du CONSOMMABLE (conso_type). Une litière ou
     * un matériel n'en a pas — `CreateFeedPurchase` ne la pose que pour la
     * catégorie « conso » — et retombait donc sur « Aliment » : la marge du lot,
     * qui compte l'aliment au CONSOMMÉ et le reste à l'ACHAT, n'en comptait
     * jamais rien. On lit d'abord la catégorie de stock de l'achat ; un achat
     * ancien, sans catégorie, reste un consommable.
     */
    public function getCategoryAttribute(): string
    {
        return match ($this->metadata['category'] ?? null) {
            'litieres'  => self::NATURE_LITIERE,
            'materiels' => self::NATURE_MATERIEL,
            default     => $this->metadata['conso_type'] ?? 'Aliment',
        };
    }

    /** L'aliment se compte au CONSOMMÉ ; tout autre achat, à l'ACHAT. */
    public function isFeed(): bool
    {
        return $this->category === 'Aliment';
    }
}