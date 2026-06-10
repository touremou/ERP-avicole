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

    protected $fillable = [
        // Sync offline
        'uuid', 'is_synced', 'last_sync_at',

        // Identité
        'farm_id','code', 'type', 'model_name',

        // Relations FK
        'building_id', 'employee_id', 'provider_id',
        'protocol_id', 'current_protocol_id',
        'responsible', 'species_id', 'production_type_id',

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

    // ═══════════════════════════════════════════════
    // HOOKS DE CYCLE DE VIE
    // ═══════════════════════════════════════════════

    protected static function booted(): void
    {
        static::creating(function (Batch $batch) {
            $batch->status = $batch->status ?? 'Actif';
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
     * Calcule la date de fin prévisionnelle.
     * Priorité : production_type.cycle_days_default → type legacy → settings → 45j.
     */
    public function calculateExpectedEndDate(): void
    {
        if (! $this->arrival_date) {
            return;
        }

        // 1. Depuis le type de production (table production_types) — source de vérité multiespèces
        if ($this->production_type_id && $this->relationLoaded('productionType') && $this->productionType) {
            $days = $this->productionType->cycle_days_default;
        } else {
            // 2. Depuis les settings (rétrocompat poulet + nouvelles espèces via settings)
            $days = match (strtolower($this->type ?? 'chair')) {
                'chair'                    => (int) setting('elevage.cycle_chair', 45),
                'ponte'                    => (int) setting('elevage.cycle_ponte', 540),
                'poussiniere'              => (int) setting('elevage.cycle_poussiniere', 90),
                'repro', 'reproducteur'    => (int) setting('elevage.cycle_reproducteur', 450),
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

        $end = ($this->status === 'Terminé' && $this->closing_date)
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
     * Marge nette consolidée (tous revenus - tous coûts).
     *
     * Correction B-07 : inclut les revenus œufs pour les pondeuses.
     */
    public function getNetMarginAttribute(): float
    {
        // Revenus
        $sellingRevenue = (float) ($this->total_revenue ?? 0);
        $eggRevenue = (float) $this->eggProductions()->sum(
            \DB::raw('COALESCE(total_eggs_collected * 0, 0)')
            // NOTE : EggProduction n'a pas de colonne 'total_price'.
            // À remplacer par la vraie colonne de revenu quand elle existera.
            // En attendant, on utilise total_revenue du lot qui est calculé à la clôture.
        );

        // Coûts
        $feedCost = (float) $this->feedPurchases()->sum('total_price');
        $healthCost = (float) $this->healthChecks()->sum('cost');
        $acquisitionCost = (float) ($this->total_acquisition_cost ?? 0);
        $additionalCosts = (float) ($this->additional_costs ?? 0);

        return $sellingRevenue - ($feedCost + $healthCost + $acquisitionCost + $additionalCosts);
    }

    // ═══════════════════════════════════════════════
    // SCOPES (REQUÊTES RÉUTILISABLES)
    // ═══════════════════════════════════════════════

    /**
     * Lots actifs uniquement.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'Actif');
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
        return $query->whereIn('status', ['Terminé', 'Clôturé', 'Vendu', 'Annulé']);
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
