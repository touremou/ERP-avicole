<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use App\Traits\HasStandardUuid;
use App\Traits\BelongsToFarm;

/**
 * Model Batch — Cœur métier de l'ERP AviSmart.
 *
 * Décisions d'architecture (AUDIT §2.1) :
 * - current_quantity = SEULE source de vérité pour l'effectif vivant
 * - qty_alive = accessor (alias de current_quantity), PAS stocké
 * - qty_dead = mortalité d'arrivage uniquement, figé après création
 * - total_mortality = accessor calculé depuis qty_dead + SUM(daily_checks.mortality)
 *
 * Observers enregistrés dans AppServiceProvider :
 * - BatchObserver : alertes mortalité, cascade soft-delete
 * - DailyCheckObserver : impact sur current_quantity (lockForUpdate)
 */
class Batch extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    /**
     * Statuts du cycle de vie d'un lot (valeurs stockées en base dans la
     * colonne `status`). Source unique de vérité référencée par les
     * Actions (CreateBatch, CloseBatch, ReopenBatch, TransferBatch,
     * UpdateBatch), les Requests de validation et les vues `batches/*`.
     *
     * ⚠️ Ces valeurs sont historiques (françaises, avec accents) — un
     * renommage casserait les enregistrements existants.
     */
    public const STATUS_ACTIF   = 'Actif';
    public const STATUS_TERMINE = 'Terminé';
    public const STATUS_CLOTURE = 'Clôturé';
    public const STATUS_VENDU   = 'Vendu';
    public const STATUS_ANNULE  = 'Annulé';

    /**
     * Statuts « archivés » : le lot n'est plus en production active.
     * Référencé par scopeArchived() et les rapports/plannings.
     */
    public const STATUS_ARCHIVED = [
        self::STATUS_TERMINE,
        self::STATUS_CLOTURE,
        self::STATUS_VENDU,
        self::STATUS_ANNULE,
    ];

    /**
     * Statuts sélectionnables manuellement depuis le formulaire d'édition
     * (resources/views/batches/edit.blade.php). « Clôturé » et « Vendu »
     * sont positionnés exclusivement par des actions dédiées (clôture de
     * lot, vente de réforme) et ne sont donc pas proposés ici.
     */
    public const EDITABLE_STATUSES = [
        self::STATUS_ACTIF,
        self::STATUS_TERMINE,
        self::STATUS_ANNULE,
    ];

    protected $fillable = [
        // Sync offline
        'uuid', 'is_synced', 'last_sync_at',

        // Identité
        'farm_id','code', 'type', 'model_name',

        // Relations FK
        'building_id', 'employee_id', 'provider_id',
        'protocol_id', 'current_protocol_id',
        'species_id', 'production_type_id',

        // Effectifs — current_quantity est la source de vérité
        'initial_quantity', 'current_quantity', 'qty_dead',
        'qty_males', 'qty_females', 'mating_ratio',

        // Technique
        'production_phase', 'planned_density', 'avg_weight_start',
        'chick_state', 'allocated_surface', 'arrival_mortality_rate',

        // Dates
        'arrival_date', 'expected_end_date', 'start_date',
        'transfer_date', 'closing_date',

        // Financier
        'buy_price_per_unit', 'total_acquisition_cost',
        'actual_sell_price_per_unit', 'total_revenue',
        'margin', 'additional_costs',

        // État
        'status', 'observations', 'photo_path',

        // Vaccinations
        'vaccination_received', 'vaccination_details',

        // Transfert
        'transfer_history',

        // Scission
        'parent_batch_id',

        // Campagne saisonnière (Tabaski...)
        'campaign_id',
    ];

    // Note : 'qty_alive' est VOLONTAIREMENT absent de $fillable.
    // C'est un accessor calculé (alias de current_quantity).

    protected $casts = [
        'is_synced' => 'boolean',
        'last_sync_at' => 'datetime',
        'arrival_date' => 'date',
        'expected_end_date' => 'date',
        'closing_date' => 'date',
        'start_date' => 'date',
        'transfer_date' => 'date',
        'buy_price_per_unit' => 'decimal:2',
        'total_acquisition_cost' => 'decimal:2',
        'actual_sell_price_per_unit' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'additional_costs' => 'decimal:2',
        'margin' => 'decimal:2',
        'avg_weight_start' => 'decimal:3',
        'planned_density' => 'float',
        'arrival_mortality_rate' => 'float',
        'mating_ratio' => 'float',
        'transfer_history' => 'array',
        'current_quantity' => 'integer',
        'initial_quantity' => 'integer',
        'qty_dead' => 'integer',
        'vaccination_received' => 'boolean',
    ];

    // ═══════════════════════════════════════════════
    // RELATIONS
    // ═══════════════════════════════════════════════

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function currentProtocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class, 'current_protocol_id');
    }

    public function parentBatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_batch_id');
    }

    public function childBatches(): HasMany
    {
        return $this->hasMany(self::class, 'parent_batch_id');
    }

    public function dailyChecks(): HasMany
    {
        return $this->hasMany(DailyCheck::class);
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(HealthCheck::class);
    }

    public function feedPurchases(): HasMany
    {
        return $this->hasMany(FeedPurchase::class);
    }

    public function eggProductions(): HasMany
    {
        return $this->hasMany(EggProduction::class);
    }

    /**
     * Dépenses directes rattachées au lot (registre des dépenses).
     * Seules les dépenses validées sont comptées dans la marge nette.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function milkProductions(): HasMany
    {
        return $this->hasMany(MilkProduction::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(BatchTask::class);
    }

    public function species(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Species::class);
    }

    public function productionType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\ProductionType::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    // ═══════════════════════════════════════════════
    // HOOKS DE CYCLE DE VIE
    // ═══════════════════════════════════════════════

    protected static function booted(): void
    {
        // Invariant taxonomique : quand un type de production est rattaché, il
        // est la SOURCE DE VÉRITÉ. On en dérive `type` (slug legacy) et
        // `species_id` pour que les trois champs ne divergent jamais. S'exécute
        // avant creating/updating, donc calculateExpectedEndDate voit déjà le
        // type synchronisé.
        static::saving(function (Batch $batch) {
            $batch->syncTaxonomyFromProductionType();
        });

        static::creating(function (Batch $batch) {
            $batch->status = $batch->status ?? self::STATUS_ACTIF;
            $batch->chick_state = $batch->chick_state ?? 'Normal';
            $batch->calculateExpectedEndDate();
        });

        static::updating(function (Batch $batch) {
            if ($batch->isDirty(['type', 'arrival_date', 'production_type_id'])) {
                $batch->calculateExpectedEndDate();
            }
        });

        // NOTE : Les hooks d'impact sur current_quantity sont dans DailyCheckObserver
        // Les hooks d'alerte mortalité et cascade soft-delete sont dans BatchObserver
    }

    /**
     * Aligne `type` (slug legacy) et `species_id` sur le type de production
     * rattaché, qui fait foi. Sans effet si aucun production_type_id n'est
     * défini (lots volaille mono-espèce historiques : `type` reste tel quel).
     *
     * Ne requête le référentiel qu'à la création ou lorsque le type de
     * production change réellement, pour éviter une requête à chaque save.
     */
    public function syncTaxonomyFromProductionType(): void
    {
        if (! $this->production_type_id) {
            return;
        }

        if ($this->exists && ! $this->isDirty('production_type_id')) {
            return;
        }

        $productionType = ProductionType::find($this->production_type_id);
        if (! $productionType) {
            return;
        }

        $this->type = $productionType->slug;
        $this->species_id = $productionType->species_id;
    }

    /**
     * Calcule la date de fin prévisionnelle.
     * Priorité : production_type.cycle_days_default → type legacy → settings → 45j.
     */
    public function calculateExpectedEndDate(): void
    {
        if (! $this->arrival_date) {
            return;
        }

        // 1. Depuis le type de production (table production_types) — source de vérité multiespèces
        if ($this->production_type_id && $this->productionType?->cycle_days_default) {
            $days = $this->productionType->cycle_days_default;
        } else {
            // 2. Depuis les settings (rétrocompat poulet + nouvelles espèces via settings)
            $days = match (strtolower($this->type ?? 'chair')) {
                'chair' => match ($this->species?->slug) {
                    'dinde'  => (int) setting('elevage.cycle_dinde_chair', 120),
                    'caille' => (int) setting('elevage.cycle_caille_chair', 42),
                    default  => (int) setting('elevage.cycle_chair', 45),
                },
                'ponte' => match ($this->species?->slug) {
                    'caille' => (int) setting('elevage.cycle_caille_ponte', 240),
                    default  => (int) setting('elevage.cycle_ponte', 540),
                },
                'poussiniere'              => (int) setting('elevage.cycle_poussiniere', 90),
                'repro', 'reproducteur'    => match ($this->species?->slug) {
                    'mouton' => (int) setting('elevage.cycle_ovin_reproducteur', 180),
                    default  => (int) setting('elevage.cycle_reproducteur', 450),
                },
                'laitiere'                 => (int) setting('elevage.cycle_caprin_lait', 210),
                'engraissement'            => (int) setting('elevage.cycle_ovin_engraissement', 90),
                default                    => 45,
            };
        }

        $this->expected_end_date = Carbon::parse($this->arrival_date)->addDays($days);
    }

    /** Retourne le label de l'espèce (avec fallback sur type legacy pour le poulet) */
    public function getSpeciesLabelAttribute(): string
    {
        return $this->species?->name_fr ?? ucfirst($this->type ?? 'Inconnu');
    }

    /** Indique si le lot suit des ruminants */
    public function isRuminant(): bool
    {
        return $this->species?->isRuminant() ?? false;
    }

    /** Indique si le lot est piscicole */
    public function isAquaculture(): bool
    {
        return $this->species?->isAquaculture() ?? false;
    }

    /** Indique si le lot est avicole (poulet ou autre volaille) */
    public function isVolaille(): bool
    {
        return $this->species === null || $this->species->isVolaille();
    }

    /** Indique si le lot est suivi via le GMQ (ruminants, porcins, lapins) */
    public function isGmqTracked(): bool
    {
        return $this->species?->isGmqTracked() ?? false;
    }

    /** Indique si le lot est actuellement en production (statut Actif). */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIF;
    }

    /** Indique si le lot est archivé (terminé, clôturé, vendu ou annulé). */
    public function isArchived(): bool
    {
        return in_array($this->status, self::STATUS_ARCHIVED, true);
    }

    /**
     * Indique si le lot fait l'objet d'un suivi de ponte (collecte d'œufs,
     * couvoir...). Piloté par le type de production de l'espèce, avec
     * repli sur l'ancienne logique (type legacy) pour les lots volaille
     * sans species_id.
     */
    public function tracksEggs(): bool
    {
        return $this->productionType
            ? $this->productionType->tracks('eggs')
            : in_array($this->type, ['ponte', 'repro', 'reproducteur']);
    }

    /**
     * Indique si le lot fait l'objet d'une collecte de lait (chèvres
     * laitières, vaches...). Piloté par le type de production, avec repli
     * sur le flag tracks_milk de l'espèce.
     */
    public function tracksMilk(): bool
    {
        return $this->productionType
            ? $this->productionType->tracks('milk')
            : ($this->species?->tracks_milk ?? false);
    }

    /**
     * Phases d'aliment volaille (noms d'articles de stock attendus dans la
     * catégorie « conso »), par secteur Chair / Ponte. Source unique de
     * vérité partagée par la vue show (cartes de stock par phase), le modal
     * d'achat direct (feed-modal) et le calcul du prix moyen de l'aliment
     * (BatchController). Les lots de ponte/reproducteur relèvent du secteur
     * Ponte.
     */
    public const FEED_PHASES = [
        'Chair' => [
            'Chair Démarrage',
            'Chair Croissance',
            'Chair Finition',
        ],
        'Ponte' => [
            'Ponte Démarrage (Poussin)',
            'Ponte Croissance (Poulette)',
            'Ponte 1 (Pic de ponte)',
            'Ponte 2 (Entretien)',
        ],
    ];

    /**
     * Secteur d'aliment volaille du lot : « Chair » ou « Ponte ».
     * Les types ponte/repro/reproducteur relèvent du secteur Ponte ;
     * chair et poussinière relèvent du secteur Chair.
     */
    public function feedSector(): string
    {
        return in_array(strtolower((string) $this->type), ['ponte', 'repro', 'reproducteur'], true)
            ? 'Ponte'
            : 'Chair';
    }

    /**
     * Liste des phases d'aliment attendues pour ce lot (secteur Chair/Ponte).
     *
     * @return array<int, string>
     */
    public function feedPhases(): array
    {
        return self::FEED_PHASES[$this->feedSector()];
    }

    // ═══════════════════════════════════════════════
    // ACCESSEURS — EFFECTIFS
    // ═══════════════════════════════════════════════

    /**
     * Alias de current_quantity pour compatibilité ascendante.
     *
     * DÉCISION ARCHITECTURE §2.1 : qty_alive n'est plus stocké en DB.
     * Tout code lisant $batch->qty_alive fonctionnera toujours.
     *
     * @deprecated Utiliser $batch->current_quantity directement.
     */
    public function getQtyAliveAttribute(): int
    {
        return (int) ($this->attributes['current_quantity'] ?? 0);
    }

    /**
     * Mortalité totale cumulée (arrivage + élevage).
     *
     * Correction B-06 : distinction claire entre qty_dead (arrivage)
     * et daily_checks.mortality (élevage).
     */
    public function getTotalMortalityAttribute(): int
    {
        return (int) ($this->qty_dead ?? 0)
             + (int) $this->dailyChecks()->sum('mortality');
    }

    /**
     * Taux de mortalité cumulé (%).
     *
     * Base de calcul : mortalité totale / (initial_quantity + qty_dead)
     * car initial_quantity = sujets vivants reçus (hors morts au transport).
     */
    public function getMortalityRateAttribute(): float
    {
        $base = $this->initial_quantity + ($this->qty_dead ?? 0);

        if ($base <= 0) {
            return 0;
        }

        return round(($this->total_mortality / $base) * 100, 2);
    }

    // ═══════════════════════════════════════════════
    // ACCESSEURS — PERFORMANCE ZOOTECHNIQUE
    // ═══════════════════════════════════════════════

    /**
     * Âge actuel en jours (J1 = jour d'arrivée).
     *
     * Correction S-13 : Carbon::parse safe en cas de type inattendu.
     */
    public function getAgeAttribute(): int
    {
        if (! $this->arrival_date) {
            return 0;
        }

        $start = Carbon::parse($this->arrival_date)->startOfDay();

        $end = ($this->status === self::STATUS_TERMINE && $this->closing_date)
            ? Carbon::parse($this->closing_date)->startOfDay()
            : now()->startOfDay();

        return (int) $start->diffInDays($end) + 1;
    }

    /**
     * Indice de Consommation (FCR — Feed Conversion Ratio).
     *
     * Formule : Total aliment consommé (kg) / Biomasse produite (kg)
     * Biomasse = effectif vivant × poids moyen dernier pointage
     */
    public function getFcrAttribute(): float
    {
        $totalFeed = (float) $this->dailyChecks()->sum('feed_consumed');
        $lastCheck = $this->dailyChecks()
            ->whereNotNull('avg_weight')
            ->latest('check_date')
            ->first();

        $lastWeight = $lastCheck ? (float) $lastCheck->avg_weight : 0;

        if ($lastWeight <= 0 || $this->current_quantity <= 0) {
            return 0;
        }

        $biomass = $this->current_quantity * $lastWeight;

        return round($totalFeed / max($biomass, 1), 2);
    }

    /**
     * Phase actuelle basée sur l'âge et le type.
     */
    public function getCurrentPhaseAttribute(): string
    {
        $ageDays = $this->age;
        $type = strtolower($this->type ?? 'chair');

        return match ($type) {
            'chair' => $this->getBroilerPhase($ageDays),
            'ponte', 'repro', 'reproducteur' => $this->getLayerPhase($ageDays),
            'poussiniere' => $ageDays <= 42 ? 'Démarrage' : 'Croissance',
            default => 'Production',
        };
    }

    // ═══════════════════════════════════════════════
    // ACCESSEURS — FINANCIER
    // ═══════════════════════════════════════════════

    /**
     * Marge nette consolidée (revenus - coûts).
     *
     * Limite connue : le revenu des œufs n'est PAS rattaché au lot. Les œufs
     * collectés alimentent un stock mutualisé (cf. EggProduction::getMapForStockSync)
     * puis sont vendus via le module Stock/Ventes sans batch_id (les lignes de
     * vente ne portent un lot que pour les animaux vifs, jamais pour 'oeufs').
     * La marge d'un lot de ponte reflète donc la vente de réforme (total_revenue),
     * le chiffre d'affaires des œufs étant suivi globalement au niveau ferme.
     */
    public function getNetMarginAttribute(): float
    {
        // Revenus enregistrés sur le lot (vente de réforme calculée à la clôture).
        $sellingRevenue = (float) ($this->total_revenue ?? 0);

        // Coûts
        $feedCost = (float) $this->feedPurchases()->sum('total_price');
        $healthCost = (float) $this->healthChecks()->sum('cost');
        $acquisitionCost = (float) ($this->total_acquisition_cost ?? 0);
        $additionalCosts = (float) ($this->additional_costs ?? 0);
        // Dépenses directes validées rattachées au lot (registre des dépenses).
        $directExpenses = (float) $this->expenses()->where('status', 'valide')->sum('amount');

        return $sellingRevenue - ($feedCost + $healthCost + $acquisitionCost + $additionalCosts + $directExpenses);
    }

    // ═══════════════════════════════════════════════
    // SCOPES (REQUÊTES RÉUTILISABLES)
    // ═══════════════════════════════════════════════

    /**
     * Lots actifs uniquement.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIF);
    }
    /**
     * Filtre uniquement les lots physiques (animaux vivants).
     * Exclut les lots virtuels (comme les stocks d'œufs).
     */
    public function scopeLive($query)
    {
        return $query->where('initial_quantity', '>', 0);
    }

    /**
     * Filtre par type d'exploitation.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Lots en surmortalité (requête SQL pure, pas d'accessor PHP).
     *
     * Correction B-01 : remplace le whereRaw('total_mortalite/...') inexistant.
     *
     * @param float $thresholdPercent Seuil de mortalité cumulée (%)
     */
    public function scopeCritical($query, float $thresholdPercent = 5.0)
    {
        return $query->where('initial_quantity', '>', 0)
            ->whereRaw(
                '(
                    (qty_dead + COALESCE((
                        SELECT SUM(dc.mortality)
                        FROM daily_checks dc
                        WHERE dc.batch_id = batches.id
                        AND dc.deleted_at IS NULL
                    ), 0))
                    / (initial_quantity + qty_dead)
                ) * 100 > ?',
                [$thresholdPercent]
            );
    }

    /**
     * Lots archivés (terminés, clôturés, vendus, annulés).
     */
    public function scopeArchived($query)
    {
        return $query->whereIn('status', self::STATUS_ARCHIVED);
    }

    // ═══════════════════════════════════════════════
    // MÉTHODES PRIVÉES
    // ═══════════════════════════════════════════════

    private function getBroilerPhase(int $days): string
    {
        if ($days <= 14) return 'Démarrage';
        if ($days <= 28) return 'Croissance';

        return 'Finition';
    }

    private function getLayerPhase(int $days): string
    {
        if ($days <= 42) return 'Démarrage';
        if ($days <= 126) return 'Croissance';
        if ($days <= 147) return 'Pré-Ponte';
        if ($days <= 500) return 'Ponte';

        return 'Réforme';
    }
}
