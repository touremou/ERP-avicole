<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use App\Traits\HasStandardUuid;
use App\Traits\BelongsToFarm;
use App\Actions\DailyCheck\SyncManureCollection;
use App\Models\WaterReading;
use App\Models\EnergyReading;

/**
 * Model Batch — Cœur métier de l'ERP AviSmart.
 *
 * Décisions d'architecture (AUDIT §2.1) :
 * - current_quantity = SEULE source de vérité pour l'effectif vivant
 * - qty_alive = accessor (alias de current_quantity), PAS stocké
 * - qty_dead = mortalité d'arrivage uniquement, figé après création
 * - total_mortality = accessor calculé depuis qty_dead + SUM(daily_checks.mortality)
 *
 * Hooks d'effectif :
 * - BatchObserver (enregistré dans AppServiceProvider) : alertes mortalité, cascade soft-delete
 * - DailyCheck::booted() : impact sur current_quantity (lockForUpdate)
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
        'farm_id','code', 'model_name',

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

    /**
     * Dernier pointage du lot (par date). Permet de charger uniquement le
     * pointage le plus récent (eager loading) au lieu de tout l'historique —
     * évite un N+1 / une surcharge mémoire dans les listes (cf. dashboard).
     */
    public function latestDailyCheck(): HasOne
    {
        return $this->hasOne(DailyCheck::class)->latestOfMany('check_date');
    }

    /**
     * Date du dernier renouvellement de litière (pointage avec litière changée).
     * Null si aucune litière n'a encore été renouvelée sur le lot.
     */
    public function getLastLitterChangeAtAttribute(): ?\Carbon\Carbon
    {
        $date = $this->dailyChecks()
            ->where('litter_changed', true)
            ->max('check_date');

        return $date ? \Carbon\Carbon::parse($date) : null;
    }

    /**
     * Nombre de jours écoulés depuis le dernier renouvellement de litière.
     * Indicateur de biosécurité (ammoniac, coccidiose…) affiché au pointage.
     * Null si la litière n'a jamais été changée sur le lot.
     */
    public function getDaysSinceLitterChangeAttribute(): ?int
    {
        $last = $this->last_litter_change_at;

        return $last ? (int) $last->startOfDay()->diffInDays(now()->startOfDay()) : null;
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(HealthCheck::class);
    }

    public function healthIncidents(): HasMany
    {
        return $this->hasMany(HealthIncident::class);
    }

    /**
     * Incident sanitaire OUVERT plaçant ce lot en quarantaine (null sinon).
     *
     * Biosécurité : tant qu'une quarantaine est active, la vente à la tête
     * (ValidateSale), la mutation (TransferBatch) et la collecte d'œufs
     * (RecordEggCollection) sont refusées CÔTÉ SERVEUR. La levée passe
     * exclusivement par le circuit santé (résolution ou toggle incident).
     */
    public function activeQuarantine(): ?HealthIncident
    {
        return $this->healthIncidents()
            ->where('is_quarantined', true)
            ->where('status', '!=', HealthIncident::STATUS_RESOLVED)
            ->latest('quarantine_started_at')
            ->first();
    }

    public function isQuarantined(): bool
    {
        return $this->healthIncidents()
            ->where('is_quarantined', true)
            ->where('status', '!=', HealthIncident::STATUS_RESOLVED)
            ->exists();
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
        // est la SOURCE DE VÉRITÉ. On en dérive `species_id` pour que les deux
        // champs ne divergent jamais. S'exécute avant creating/updating, donc
        // calculateExpectedEndDate voit déjà la taxonomie synchronisée.
        static::saving(function (Batch $batch) {
            $batch->syncTaxonomyFromProductionType();
        });

        static::creating(function (Batch $batch) {
            $batch->status = $batch->status ?? self::STATUS_ACTIF;
            $batch->chick_state = $batch->chick_state ?? 'Normal';
            $batch->calculateExpectedEndDate();
        });

        static::updating(function (Batch $batch) {
            if ($batch->isDirty(['arrival_date', 'production_type_id'])) {
                $batch->calculateExpectedEndDate();
            }
        });

        // NOTE : Les hooks d'impact sur current_quantity sont dans DailyCheck::booted()
        // Les hooks d'alerte mortalité et cascade soft-delete sont dans BatchObserver
    }

    /**
     * Aligne `species_id` sur le type de production rattaché, qui fait foi.
     * Sans effet si aucun production_type_id n'est défini.
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

        $this->species_id = $productionType->species_id;
    }

    /**
     * Slug legacy du type de production rattaché (ex. 'chair', 'ponte').
     *
     * `type` n'est plus une colonne de `batches` (cf. migration
     * 2026_06_13_000005_drop_type_column_from_batches) : c'est désormais un
     * accessor calculé à partir de `productionType`, conservé pour la
     * compatibilité ascendante avec le code existant (filtres, libellés,
     * calculs de phase/secteur d'aliment).
     */
    public function getTypeAttribute(): ?string
    {
        return $this->productionType?->slug;
    }

    /**
     * No-op : `type` n'étant plus une colonne, toute affectation directe
     * (legacy, factories, tests) est silencieusement ignorée. Piloter la
     * taxonomie via `production_type_id`.
     */
    public function setTypeAttribute($value): void
    {
        // Intentionnellement vide.
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

    /**
     * Le lot est-il élevé sur litière (suivi du renouvellement + valorisation
     * du fumier) ? Concerne les volailles et les lapins (litière profonde).
     * Pilote l'affichage du bloc « Litière / Fumier » du pointage journalier.
     */
    public function usesLitter(): bool
    {
        return $this->isVolaille() || $this->species?->family === 'lagomorphe';
    }

    /**
     * Le picage / cannibalisme est un trouble comportemental propre aux
     * volailles : on ne le suit que pour ces lots.
     */
    public function tracksPecking(): bool
    {
        return $this->isVolaille();
    }

    /**
     * Suivi de la boiterie (bien-être locomoteur) : pertinent pour les
     * volailles (pododermatite, croissance rapide) comme pour les mammifères
     * d'élevage (ruminants, porcins).
     */
    public function tracksLameness(): bool
    {
        return $this->isVolaille() || $this->isGmqTracked();
    }

    /**
     * Suivi de l'ambiance « air » (température/hygrométrie du bâtiment,
     * litière, abreuvement classique). Sans objet en pisciculture, où le
     * milieu est l'eau elle-même (cf. section qualité d'eau dédiée).
     */
    public function tracksAirAmbiance(): bool
    {
        return ! $this->isAquaculture();
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
     * Âge minimal (en jours) à partir duquel une collecte d'œufs est
     * biologiquement plausible pour ce lot — garde-fou zootechnique : une
     * pondeuse n'entre en ponte qu'à maturité sexuelle (≈ 18 semaines pour
     * une poule, mais ~6 pour la caille), il est aberrant de collecter des
     * œufs sur un lot en phase démarrage/croissance.
     *
     * Source pilotée par les données : première semaine où la norme de la
     * souche (production_norms.target_laying_rate > 0) prévoit une ponte, ce
     * qui gère nativement les espèces à maturité atypique. Repli sur l'entrée
     * en pré-ponte (cf. getLayerPhase) si aucune norme n'est renseignée.
     */
    public const DEFAULT_MIN_LAYING_AGE_DAYS = 126; // 18 semaines : entrée en pré-ponte

    public function minLayingAgeDays(): int
    {
        // Type de norme : on retombe sur « ponte » pour tout lot suivi en œufs
        // dont le type legacy ne serait pas explicitement ponte/repro.
        $type = strtolower((string) ($this->type ?? ''));
        if (! in_array($type, ['ponte', 'repro', 'reproducteur'], true)) {
            $type = $this->tracksEggs() ? 'ponte' : $type;
        }

        $base = ProductionNorm::where('target_laying_rate', '>', 0);
        if (in_array($type, ['ponte', 'repro', 'reproducteur'], true)) {
            $base->where('batch_type', $type);
        }

        // Priorité à la souche du lot (maturité propre à l'espèce/souche).
        if ($this->model_name) {
            $strainWeek = (clone $base)
                ->where('model_name', 'LIKE', "%{$this->model_name}%")
                ->min('week_number');
            if ($strainWeek) {
                return max(0, ((int) $strainWeek - 1) * 7);
            }
        }

        $typeWeek = $base->min('week_number');
        if ($typeWeek) {
            return max(0, ((int) $typeWeek - 1) * 7);
        }

        return self::DEFAULT_MIN_LAYING_AGE_DAYS;
    }

    /**
     * Le lot est-il en âge de pondre ? (suivi œufs ET âge ≥ seuil d'entrée
     * en ponte). Garde-fou partagé par la validation de collecte et la vue.
     */
    public function canCollectEggs(): bool
    {
        return $this->tracksEggs() && $this->age >= $this->minLayingAgeDays();
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
     * Phases d'aliment (noms d'articles de stock attendus dans la catégorie
     * « conso »), par secteur. Source unique de vérité partagée par la vue
     * show (cartes de stock par phase), le modal d'achat direct (feed-modal),
     * le Daily Check et le calcul du prix moyen de l'aliment (BatchController).
     *
     * Secteurs volaille : Chair / Ponte (cf. feedSector()).
     * Secteurs ruminants/lapin/porc : Engraissement, Laitière, Reproducteur.
     * Secteurs aquaculture : Grossissement, Alevinage.
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
        'Reproducteur' => [
            'Reproducteur Entretien',
            'Reproducteur Gestation',
            'Reproducteur Lactation',
        ],
        'Engraissement' => [
            'Engraissement Démarrage',
            'Engraissement Croissance',
            'Engraissement Finition',
        ],
        'Laitière' => [
            'Laitière Préparation vêlage',
            'Laitière Lactation',
            'Laitière Tarissement',
        ],
        'Grossissement' => [
            'Grossissement Pré-grossissement',
            'Grossissement Grossissement',
            'Grossissement Finition',
        ],
        'Alevinage' => [
            'Alevinage 1er âge',
            'Alevinage 2e âge',
        ],
    ];

    /**
     * Seuils d'âge FIXES (en jours) de présélection de la phase d'aliment dans
     * le Daily Check, par secteur (cf. FEED_PHASES). Chaque paire [âge max,
     * index de phase] est évaluée dans l'ordre ; PHP_INT_MAX sert de défaut.
     *
     * Utilisé tel quel pour les secteurs « physiologiques » (Ponte, Laitière,
     * Reproducteur), pilotés par un événement (mise en ponte, mise bas) plutôt
     * que par la durée du cycle ; et comme repli pour les secteurs de
     * croissance quand la durée de cycle est inconnue (cf.
     * FEED_GROWOUT_FRACTIONS et feedPreselectPhase()).
     */
    private const FEED_AGE_THRESHOLDS = [
        'Chair'         => [[14, 0], [28, 1], [PHP_INT_MAX, 2]],
        'Ponte'         => [[42, 0], [126, 1], [PHP_INT_MAX, 2]],
        'Reproducteur'  => [[60, 0], [180, 1], [PHP_INT_MAX, 2]],
        'Engraissement' => [[30, 0], [60, 1], [PHP_INT_MAX, 2]],
        'Laitière'      => [[60, 0], [180, 1], [PHP_INT_MAX, 2]],
        'Grossissement' => [[30, 0], [90, 1], [PHP_INT_MAX, 2]],
        'Alevinage'     => [[15, 0], [PHP_INT_MAX, 1]],
    ];

    /**
     * Fractions cumulées de la durée de cycle (production_types.cycle_days_default)
     * délimitant les phases d'aliment des secteurs de CROISSANCE — l'aliment y
     * suit l'âge proportionnellement à la durée de cycle réelle de l'espèce.
     * Ainsi un poulet de chair (cycle 45 j) et une dinde de chair (cycle 120 j)
     * basculent de phase à des âges différents mais aux mêmes stades relatifs.
     *
     * Chaque valeur est la borne haute (fraction du cycle) de la phase de même
     * index ; la dernière vaut 1.0 (fin de cycle).
     */
    private const FEED_GROWOUT_FRACTIONS = [
        'Chair'         => [0.30, 0.60, 1.0],
        'Engraissement' => [0.30, 0.65, 1.0],
        'Grossissement' => [0.20, 0.60, 1.0],
        'Alevinage'     => [0.50, 1.0],
    ];

    /**
     * Secteur d'aliment du lot, déterminé par son type de production.
     *
     * Volaille : « Chair » ou « Ponte » (ponte/repro/reproducteur relèvent
     * de Ponte ; chair et poussinière relèvent de Chair).
     * Autres espèces : Engraissement / Laitière / Reproducteur / Grossissement
     * / Alevinage selon le slug du type de production.
     */
    public function feedSector(): string
    {
        // Source de vérité : le type de production (cf. ProductionType::feedSector()).
        if ($this->productionType) {
            return $this->productionType->feedSector();
        }

        // Repli legacy : lot sans type de production (volaille mono-espèce).
        return in_array(strtolower((string) $this->type), ['ponte', 'repro', 'reproducteur'], true)
            ? 'Ponte'
            : 'Chair';
    }

    /**
     * Liste des phases d'aliment attendues pour ce lot (cf. feedSector()).
     *
     * @return array<int, string>
     */
    public function feedPhases(): array
    {
        return self::FEED_PHASES[$this->feedSector()];
    }

    /**
     * Phase d'aliment à présélectionner dans le Daily Check selon l'âge du lot.
     *
     * Secteurs de croissance (Chair, Engraissement, Grossissement, Alevinage) :
     * seuils calés sur la durée de cycle réelle de l'espèce
     * (production_types.cycle_days_default × fractions, cf.
     * FEED_GROWOUT_FRACTIONS). Secteurs physiologiques ou cycle inconnu : repli
     * sur les seuils fixes (cf. FEED_AGE_THRESHOLDS).
     */
    public function feedPreselectPhase(int $ageInDays): ?string
    {
        $sector = $this->feedSector();
        $phases = $this->feedPhases();

        // Secteurs de croissance : seuils proportionnels au cycle de l'espèce.
        $cycle = (int) ($this->productionType?->cycle_days_default ?? 0);
        if ($cycle > 0 && isset(self::FEED_GROWOUT_FRACTIONS[$sector])) {
            foreach (self::FEED_GROWOUT_FRACTIONS[$sector] as $index => $fraction) {
                if ($ageInDays <= $fraction * $cycle) {
                    return $phases[$index] ?? null;
                }
            }

            return $phases[count(self::FEED_GROWOUT_FRACTIONS[$sector]) - 1] ?? null;
        }

        // Secteurs physiologiques (ponte, repro, laitière) ou cycle inconnu.
        foreach (self::FEED_AGE_THRESHOLDS[$sector] ?? [] as [$maxAge, $phaseIndex]) {
            if ($ageInDays <= $maxAge) {
                return $phases[$phaseIndex] ?? null;
            }
        }

        return $phases[0] ?? null;
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
        // Mortalité troupeau (impacte l'effectif) + mortalité EN INFIRMERIE
        // (sujets déjà isolés : aucun impact effectif, mais bien des pertes).
        return (int) ($this->qty_dead ?? 0)
             + (int) $this->dailyChecks()->sum('mortality')
             + (int) $this->dailyChecks()->sum('mortality_infirmary');
    }

    /**
     * Solde de sujets ACTUELLEMENT isolés en infirmerie :
     * Σ mises en infirmerie − Σ retours (rétablis) − Σ morts en infirmerie.
     *
     * Ces sujets sont déjà DÉCOMPTÉS de current_quantity (l'isolement les
     * sort de l'effectif sain) : cheptel total réel = current_quantity +
     * infirmary_count.
     */
    public function getInfirmaryCountAttribute(): int
    {
        return $this->infirmaryCountExcluding(null);
    }

    /**
     * Même solde en EXCLUANT un pointage donné — utilisé par la garde de
     * saisie lors de la rectification (le pointage modifié ne doit pas se
     * compter lui-même dans le disponible).
     */
    public function infirmaryCountExcluding(?int $exceptCheckId): int
    {
        $query = $this->dailyChecks();
        if ($exceptCheckId !== null) {
            $query->where('id', '!=', $exceptCheckId);
        }

        $sums = $query->selectRaw('
            COALESCE(SUM(qty_quarantine_in), 0)  as q_in,
            COALESCE(SUM(qty_quarantine_out), 0) as q_out,
            COALESCE(SUM(mortality_infirmary), 0) as q_dead
        ')->first();

        return max(0, (int) $sums->q_in - (int) $sums->q_out - (int) $sums->q_dead);
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
     * Densité d'occupation COURANTE au sol (sujets/m²), calculée sur l'effectif
     * vivant réel — contrairement à `planned_density` figée à la mise en place.
     * Diminue avec la mortalité/les ventes. 0 si la surface n'est pas connue.
     */
    public function getCurrentDensityAttribute(): float
    {
        $surface = (float) $this->allocated_surface;

        return $surface > 0 ? round($this->current_quantity / $surface, 1) : 0.0;
    }

    /**
     * Charge pondérale courante (kg/m²) = densité × poids moyen vif. Indicateur
     * de bien-être clé en finition (chair). 0 si surface ou poids inconnus.
     */
    public function getCurrentStockingWeightAttribute(): float
    {
        $surface = (float) $this->allocated_surface;
        if ($surface <= 0) {
            return 0.0;
        }

        $lastWeight = (float) ($this->dailyChecks()
            ->whereNotNull('avg_weight')
            ->latest('check_date')
            ->value('avg_weight') ?? 0); // kg

        return $lastWeight > 0 ? round(($this->current_quantity * $lastWeight) / $surface, 1) : 0.0;
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
     * Registre de consommation aliment : UNE entrée par pointage avec
     * consommation > 0, chacune valorisée au coût unitaire résolu (coût figé à
     * la saisie en priorité, repli sur le CMP courant de l'article).
     *
     * Source UNIQUE partagée par feed_cogs ET le Journal des Flux de la fiche
     * lot : garantit que les lignes de consommation affichées correspondent
     * exactement aux pointages (une ligne par date) et que leur somme égale le
     * coût de revient total — fini la divergence entre l'historique des
     * pointages (1 ligne/date) et les mouvements de stock bruts (compensations
     * de correction comprises, qui doublaient visuellement la consommation).
     *
     * @return \Illuminate\Support\Collection<int, object{date: \Illuminate\Support\Carbon, feed_type: ?string, qty: float, unit_cost: float, amount: float}>
     */
    public function feedConsumptionLedger(): \Illuminate\Support\Collection
    {
        $checks = $this->dailyChecks()
            ->where('feed_consumed', '>', 0)
            ->orderByDesc('check_date')
            ->get(['id', 'check_date', 'feed_consumed', 'feed_unit_cost', 'feed_type']);

        if ($checks->isEmpty()) {
            return collect();
        }

        // Repli CMP courant, uniquement pour les types réellement sans coût figé.
        $missingTypes = $checks
            ->filter(fn ($c) => (float) ($c->feed_unit_cost ?? 0) <= 0)
            ->pluck('feed_type')->filter()->unique();

        $cmpByName = [];
        if ($missingTypes->isNotEmpty()) {
            $rows = \App\Models\Stock::where('category', \App\Models\Stock::CAT_CONSO)
                ->where(function ($q) use ($missingTypes) {
                    $q->whereIn('item_name', $missingTypes)
                      ->orWhereIn('feed_type', $missingTypes);
                })
                ->get(['item_name', 'feed_type', 'last_unit_price', 'unit_price']);

            foreach ($rows as $r) {
                $cmp = (float) ($r->last_unit_price ?? $r->unit_price ?? 0);
                if ($cmp <= 0) continue;
                if ($r->item_name) $cmpByName[trim($r->item_name)] = $cmp;
                if ($r->feed_type) $cmpByName[trim($r->feed_type)] = $cmp;
            }
        }

        return $checks->map(function ($c) use ($cmpByName) {
            $snapshot = (float) ($c->feed_unit_cost ?? 0);
            $unitCost = $snapshot > 0
                ? $snapshot
                : ($cmpByName[trim((string) $c->feed_type)] ?? 0);
            $qty = (float) $c->feed_consumed;

            return (object) [
                'date'      => $c->check_date,
                'feed_type' => $c->feed_type,
                'qty'       => $qty,
                'unit_cost' => $unitCost,
                'amount'    => $qty * $unitCost,
            ];
        });
    }

    /**
     * Coût de revient de l'aliment réellement CONSOMMÉ par le lot (COGS).
     *
     * Approche industrielle : la consommation journalière est valorisée au coût
     * moyen pondéré figé à la saisie (daily_checks.feed_unit_cost), que l'aliment
     * ait été acheté à l'extérieur ou produit à la provenderie. Le coût de revient
     * de la production interne est ainsi imputé au lot exactement comme un achat —
     * sans dépendre du moment où l'aliment a été acquis.
     *
     * Délègue au registre de consommation (feedConsumptionLedger) : le total
     * est par construction la somme des lignes affichées dans le Journal des
     * Flux de la fiche lot.
     */
    public function getFeedCogsAttribute(): float
    {
        return (float) $this->feedConsumptionLedger()->sum('amount');
    }

    /**
     * Coût eau + énergie imputé à ce lot via le bâtiment qu'il occupe.
     *
     * Seuls les relevés taggés avec building_id = ce lot sont comptabilisés,
     * sur la période d'élevage (arrival_date → closing_date ou aujourd'hui).
     * Retourne 0 si le lot n'a pas de bâtiment ou si aucun relevé n'est taggé.
     */
    public function getUtilityCostAttribute(): float
    {
        if (! $this->building_id) return 0.0;

        $start = $this->arrival_date?->toDateString();
        $end   = $this->closing_date?->toDateString() ?? now()->toDateString();

        $waterCost = WaterReading::where('building_id', $this->building_id)
            ->when($start, fn ($q) => $q->whereDate('reading_date', '>=', $start))
            ->whereDate('reading_date', '<=', $end)
            ->sum('cost');

        $energyCost = EnergyReading::where('building_id', $this->building_id)
            ->when($start, fn ($q) => $q->whereDate('reading_date', '>=', $start))
            ->whereDate('reading_date', '<=', $end)
            ->sum('cost');

        return (float) ($waterCost + $energyCost);
    }

    /**
     * Marge nette consolidée (revenus - coûts).
     *
     * Coût alimentaire : on retient la CONSOMMATION valorisée au coût de revient
     * (feed_cogs), et non plus la somme des achats (feedPurchases). C'est la
     * mesure industrielle correcte — l'aliment produit en interne est désormais
     * imputé au lot au même titre qu'un aliment acheté, et l'aliment acheté mais
     * non encore consommé reste un actif de stock plutôt qu'une charge du lot.
     * Les achats NON-aliment rattachés au lot (médicaments, matériel) restent
     * comptés au prix d'achat puisqu'ils ne transitent pas par la consommation.
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
        $feedCost = $this->feed_cogs;
        // Achats NON-aliment (médicaments, matériel…) : non captés par la
        // consommation, donc comptés au prix d'achat. Le tri s'appuie sur
        // l'accesseur category (metadata['conso_type'] ?? 'Aliment'), donc un
        // conso_type absent est traité comme « Aliment » et exclu d'ici.
        $nonFeedPurchases = (float) $this->feedPurchases
            ->filter(fn ($p) => $p->category !== 'Aliment')
            ->sum('total_price');
        // Coût santé = actes du registre (vaccins/traitements) + coûts de
        // traitement des INCIDENTS sanitaires (champ dédié, non capté ailleurs →
        // aucun double comptage). Ferme la boucle financière incident → marge.
        $healthCost = (float) $this->healthChecks()->sum('cost')
            + (float) $this->healthIncidents()->sum('treatment_cost');
        $acquisitionCost = (float) ($this->total_acquisition_cost ?? 0);
        $additionalCosts = (float) ($this->additional_costs ?? 0);
        // Dépenses directes validées rattachées au lot (registre des dépenses).
        $directExpenses = (float) $this->expenses()->where('status', 'valide')->sum('amount');
        // Eau + énergie imputés au bâtiment sur la période du lot.
        $utilityCost = $this->utility_cost;

        return $sellingRevenue - ($feedCost + $nonFeedPurchases + $healthCost + $acquisitionCost + $additionalCosts + $directExpenses + $utilityCost);
    }

    /**
     * Quantité totale de fumier ramassé sur le lot (kg), tous pointages confondus.
     */
    public function getManureCollectedKgAttribute(): float
    {
        return (float) $this->dailyChecks()->sum('manure_collected_kg');
    }

    /**
     * Revenu estimé du fumier ramassé, au prix unitaire courant de l'article
     * « Fumier » (stock partagé, cf. SyncManureCollection).
     *
     * Informatif uniquement : non inclus dans net_margin, par cohérence avec
     * l'exclusion du chiffre d'affaires œufs (cf. getNetMarginAttribute) — le
     * fumier alimente lui aussi un stock mutualisé, vendu sans rattachement
     * au lot d'origine.
     */
    public function getEstimatedManureRevenueAttribute(): float
    {
        $kg = $this->manure_collected_kg;

        if ($kg <= 0) {
            return 0;
        }

        $fumier = Stock::where('item_name', SyncManureCollection::ITEM_NAME)
            ->where('category', SyncManureCollection::CATEGORY)
            ->first();

        $unitPrice = (float) ($fumier?->unit_price ?? $fumier?->last_unit_price ?? 0);

        return round($kg * $unitPrice, 2);
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
     *
     * Exclut les lots VIRTUELS de traçabilité (effectif initial nul) :
     * œufs externes en transit parqués dans le bâtiment virtuel « Zone
     * Fournisseurs Externes » (cf. StartIncubation), stocks d'œufs, etc.
     *
     * À utiliser systématiquement pour toute SÉLECTION (listes déroulantes),
     * tout COMPTAGE d'effectif et tout RAPPORT : un lot virtuel ne représente
     * aucun animal réel et ne doit jamais y figurer — au même titre qu'un
     * bâtiment virtuel (cf. Building::scopePhysical).
     */
    public function scopeLive($query)
    {
        return $query->where('initial_quantity', '>', 0);
    }

    /**
     * Lot virtuel de traçabilité (aucun animal réel : effectif initial nul).
     * Pendant logique de scopeLive() pour les collections déjà chargées.
     */
    public function isVirtual(): bool
    {
        return (int) $this->initial_quantity === 0;
    }

    /**
     * Filtre par type d'exploitation (slug du type de production rattaché).
     */
    public function scopeByType($query, string $type)
    {
        return $query->whereHas('productionType', fn ($q) => $q->where('slug', $type));
    }

    /**
     * Seuil de mortalité CUMULÉE (%) au-delà duquel un lot est « critique ».
     *
     * SOURCE DE VÉRITÉ UNIQUE, partagée par l'alerte (BatchObserver), le filtre
     * « surmortalité » de l'index, le scope critical() ET le tableau de bord —
     * pour que l'alerte se déclenche exactement au taux affiché et que le
     * réglage édité par l'admin pilote bien tous ces usages.
     *
     * Clé canonique : « elevage.cumulative_mortality_alert_pct » (libellée et
     * éditable dans Paramètres › Élevage). Repli sur l'ancienne clé
     * « elevage.mortality_alert » (compatibilité), puis 5 %.
     */
    public static function cumulativeMortalityThreshold(): float
    {
        return (float) setting(
            'elevage.cumulative_mortality_alert_pct',
            setting('elevage.mortality_alert', 5)
        );
    }

    /**
     * Lots en surmortalité (requête SQL pure, pas d'accessor PHP).
     *
     * Correction B-01 : remplace le whereRaw('total_mortalite/...') inexistant.
     *
     * @param float|null $thresholdPercent Seuil de mortalité cumulée (%) ; par
     *                   défaut le seuil unifié cumulativeMortalityThreshold().
     */
    public function scopeCritical($query, ?float $thresholdPercent = null)
    {
        $thresholdPercent ??= self::cumulativeMortalityThreshold();

        // Multiplication par 100.0 AVANT la division : force l'arithmétique en
        // virgule flottante (SQLite ferait sinon une division ENTIÈRE → toujours
        // 0 quand morts < effectif, ne flaggant jamais un lot ; MySQL renvoie des
        // décimales mais on uniformise pour la cohérence et la testabilité).
        // Le seuil est INLINÉ (cast (float), aucune injection possible) plutôt que
        // lié : un paramètre lié est transmis en TEXTE à SQLite, qui compare alors
        // « 6.0 > '5' » avec une affinité de type où le numérique précède le texte
        // → toujours faux. L'inlining force une comparaison numérique sur MySQL ET
        // SQLite. COALESCE(qty_dead, 0) gère la colonne NULL (sinon « initial +
        // NULL = NULL » annulait tout le ratio → lot jamais détecté critique).
        return $query->where('initial_quantity', '>', 0)
            ->whereRaw(
                '(COALESCE(qty_dead, 0) + COALESCE((
                        SELECT SUM(dc.mortality)
                        FROM daily_checks dc
                        WHERE dc.batch_id = batches.id
                        AND dc.deleted_at IS NULL
                    ), 0)) * 100.0
                    / (initial_quantity + COALESCE(qty_dead, 0)) > ' . (float) $thresholdPercent
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
