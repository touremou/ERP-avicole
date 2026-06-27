<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\BelongsToFarm;

class Stock extends Model
{
    use HasFactory, SoftDeletes, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'category',         // oeufs, conso, litieres, materiels
        'item_name',
        'feed_type',
        'unit',             // KG, Alvéole, Sac, Unité
        'current_quantity',
        'unit_price',
        'alert_threshold',
        'last_unit_price',
        'metadata'          // JSON: poultry_type, conso_type, supplier, bag_weight
    ];

    /**
     * Rigueur Industrielle : Précision au gramme (decimal:3) pour l'aliment
     * et précision monétaire (decimal:2).
     */
    protected $casts = [
        'metadata'         => 'array', 
        'current_quantity' => 'decimal:3',
        'alert_threshold'  => 'decimal:3',
        'unit_price'       => 'decimal:2',
        'last_unit_price'  => 'decimal:2',
        'created_at'       => 'datetime',
    ];

    /**
     * Slugs canoniques des catégories de stock (valeurs stockées en base
     * dans la colonne `category`). Source unique de vérité référencée par
     * toute la logique métier (production d'œufs, consommation d'aliment,
     * etc.) afin d'éliminer les chaînes magiques « oeufs »/« conso »…
     * disséminées : un renommage se fait ici, et l'IDE retrouve tous les
     * usages.
     *
     * ⚠️ Ces catégories sont STRUCTURELLES (la production d'œufs opère
     * toujours sur CAT_OEUFS) — à ne pas confondre avec la liste d'affichage
     * pilotée par le paramètre « stocks.categories » (cf. activeCategories()).
     */
    public const CAT_OEUFS          = 'oeufs';
    public const CAT_LAIT           = 'lait';
    public const CAT_CONSO          = 'conso';
    public const CAT_PRODUITS_FINIS = 'produits_finis';
    public const CAT_LITIERES       = 'litieres';
    public const CAT_MATERIELS      = 'materiels';
    public const CAT_RECOLTES       = 'recoltes';  // production végétale : récoltes
    public const CAT_INTRANTS       = 'intrants';  // production végétale : semences, engrais, phyto

    /**
     * Métadonnées de présentation (libellé, icône, couleur) par catégorie.
     * Source unique de vérité partagée entre l'index Stocks et le formulaire
     * de création, et référencée par le paramètre « stocks.categories ».
     */
    public const CATEGORY_META = [
        self::CAT_OEUFS          => ['label' => 'Œufs',            'icon' => 'fa-egg',                'color' => 'amber',   'emoji' => '🥚'],
        self::CAT_LAIT           => ['label' => 'Lait',            'icon' => 'fa-bottle-droplet',     'color' => 'cyan',    'emoji' => '🥛'],
        self::CAT_CONSO          => ['label' => 'Aliment & Santé', 'icon' => 'fa-wheat-awn',          'color' => 'emerald', 'emoji' => '🌾'],
        self::CAT_PRODUITS_FINIS => ['label' => 'Produits Finis',  'icon' => 'fa-drumstick-bite',     'color' => 'rose',    'emoji' => '🥩'],
        self::CAT_LITIERES       => ['label' => 'Litières',        'icon' => 'fa-leaf',               'color' => 'purple',  'emoji' => '🍂'],
        self::CAT_MATERIELS      => ['label' => 'Matériel',        'icon' => 'fa-screwdriver-wrench', 'color' => 'blue',    'emoji' => '🛠️'],
        self::CAT_RECOLTES       => ['label' => 'Récoltes',        'icon' => 'fa-wheat-awn',          'color' => 'green',   'emoji' => '🌽'],
        self::CAT_INTRANTS       => ['label' => 'Intrants',        'icon' => 'fa-spray-can',          'color' => 'lime',    'emoji' => '🌱'],
    ];

    /**
     * Catégories de stock actives, pilotées par le paramètre
     * « stocks.categories » (Paramètres > Stocks). Chaque catégorie est
     * enrichie de ses métadonnées de présentation depuis CATEGORY_META ;
     * une catégorie inconnue reçoit un rendu générique plutôt que de casser
     * l'affichage. Si le paramètre est vide, on retombe sur toutes les
     * catégories connues.
     *
     * @return array<string, array{label:string, icon:string, color:string}>
     */
    public static function activeCategories(): array
    {
        $configured = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) setting('stocks.categories', ''))
        )));

        if (empty($configured)) {
            $configured = array_keys(self::CATEGORY_META);
        }

        $categories = [];
        foreach ($configured as $slug) {
            $categories[$slug] = self::CATEGORY_META[$slug] ?? [
                'label' => ucfirst(str_replace('_', ' ', $slug)),
                'icon'  => 'fa-box',
                'color' => 'slate',
                'emoji' => '📦',
            ];
        }

        return $categories;
    }

    /**
     * Correspondance entre la nomenclature « produit vendu/expédié »
     * (SaleItem::product_type / DispatchItem::product_type, ex: oeufs,
     * lait, aliment, produits_finis, materiel) et la catégorie de stock
     * (Stock::category, cf. CATEGORY_META). Seuls les product_type listés
     * dans SaleItem::STOCK_TYPES / DispatchItem::STOCK_TYPES (oeufs, lait,
     * aliment, produits_finis, materiel) déstockent réellement un article
     * du magasin — cf. requiresDestock(). Source unique de vérité utilisée
     * par ValidateSale, CancelSale, CreateDispatch et les formulaires de
     * vente/expédition (sélection des stocks disponibles par ligne).
     *
     * 'lait' et 'produits_finis' rejoignent ce mapping pour fiabiliser le
     * lien stock<->vente : le stock « Lait » est alimenté par
     * MilkProductionController, et « produits_finis » par
     * SlaughterController::transferToStock / ChickDispatchController
     * (poussins d'un jour, découpe...) — ces articles doivent pouvoir être
     * sélectionnés et décrémentés depuis une vente, comme oeufs/aliment.
     */
    public const PRODUCT_TYPE_TO_CATEGORY = [
        'oeufs'          => self::CAT_OEUFS,
        'lait'           => self::CAT_LAIT,
        'aliment'        => self::CAT_CONSO,
        'produits_finis' => self::CAT_PRODUITS_FINIS,
        'materiel'       => self::CAT_MATERIELS,
        'litieres'       => self::CAT_LITIERES,
        'recoltes'       => self::CAT_RECOLTES,
        'intrants'       => self::CAT_INTRANTS,
    ];

    /**
     * Inverse de PRODUCT_TYPE_TO_CATEGORY : pour une catégorie de stock, le
     * product_type d'expédition/vente correspondant. Permet d'aligner la liste
     * des articles expédiables sur les catégories de stock réellement actives
     * (cf. activeCategories) au lieu d'une liste codée en dur.
     */
    public const CATEGORY_TO_PRODUCT_TYPE = [
        self::CAT_OEUFS          => 'oeufs',
        self::CAT_LAIT           => 'lait',
        self::CAT_CONSO          => 'aliment',
        self::CAT_PRODUITS_FINIS => 'produits_finis',
        self::CAT_MATERIELS      => 'materiel',
        self::CAT_LITIERES       => 'litieres',
        self::CAT_RECOLTES       => 'recoltes',
        self::CAT_INTRANTS       => 'intrants',
    ];

    /**
     * Types d'articles « stock » expédiables, alignés sur les catégories de
     * stock ACTIVES (paramètre stocks.categories). Chaque entrée : product_type
     * + libellé d'affichage. Source unique pour le formulaire d'expédition.
     *
     * @return array<int, array{type: string, label: string}>
     */
    public static function shippableStockTypes(): array
    {
        $out = [];
        foreach (self::activeCategories() as $slug => $meta) {
            if (isset(self::CATEGORY_TO_PRODUCT_TYPE[$slug])) {
                $out[] = ['type' => self::CATEGORY_TO_PRODUCT_TYPE[$slug], 'label' => $meta['label']];
            }
        }
        return $out;
    }

    /**
     * Catégorie de stock correspondant à un product_type de ligne de
     * vente/expédition. Repli sur « materiels » pour tout product_type
     * inconnu (cohérent avec le comportement historique).
     */
    public static function categoryForProductType(string $productType): string
    {
        return self::PRODUCT_TYPE_TO_CATEGORY[$productType] ?? self::CAT_MATERIELS;
    }

    // -----------------------
    // RELATIONS
    // -----------------------

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest();
    }

    // -----------------------
    // ACCESSEURS (LOGIQUE MÉTIER & BI)
    // -----------------------

    /**
     * Valeur financière de l'inventaire.
     */
    public function getTotalValueAttribute(): float
    {
        return (float) ($this->current_quantity * ($this->last_unit_price ?? 0));
    }

    /**
     * Détermine si le seuil de sécurité est franchi.
     */
    public function getIsLowAttribute(): bool
    {
        return (float) $this->current_quantity <= (float) $this->alert_threshold;
    }

    /**
     * Traduction visuelle du stock d'œufs.
     * Conversion décimale -> Alvéoles (30 œufs/Alv).
     */
    public function getEggBreakdownAttribute(): array
    {
        if ($this->unit !== 'Alvéole') return [];

        $totalQty = (float) $this->current_quantity;
        $fullTrays = floor($totalQty);
        $remainingEggs = \App\Services\UnitConverter::traysToEggs($totalQty - $fullTrays);

        return [
            'trays' => (int) $fullTrays,
            'eggs'  => (int) $remainingEggs,
            'label' => $fullTrays . ' Alv. + ' . $remainingEggs . ' œufs'
        ];
    }

    /**
     * Estimation du nombre de sacs restants (Standard 50kg).
     * Crucial pour l'inventaire physique du magasinier.
     */
    public function getSacksEstimateAttribute(): float
    {
        if ($this->unit !== 'KG' || $this->category !== self::CAT_CONSO) return 0;

        return \App\Services\UnitConverter::kgToSacks(
            (float) $this->current_quantity,
            $this->metadata['bag_weight'] ?? null
        );
    }

    // -----------------------
    // SCOPES & HELPERS
    // -----------------------

    public function scopeCategory($query, $type)
    {
        return $query->where('category', $type);
    }

    /**
     * Accès sécurisé aux métadonnées JSON.
     */
    public function getMeta($key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Badge de couleur pour le Dashboard (AviSmart UI).
     */
    public function getStatusColorAttribute(): string
    {
        if ($this->current_quantity <= 0) return 'rose';
        if ($this->is_low) return 'orange';
        return 'emerald';
    }
}