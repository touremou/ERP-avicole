<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use App\Traits\BelongsToFarm;
use App\Traits\ReferencesEmployee;
use App\Traits\HasStandardUuid;

/**
 * Cycle de culture (module Production Végétale).
 *
 * Équivalent fonctionnel du `Batch` côté élevage : l'unité de pilotage d'une
 * production, du semis à la récolte. On en réutilise les patterns (uuid, sync,
 * statuts en constantes, marge nette calculée) sans le vocabulaire animal.
 */
class CropCycle extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm, ReferencesEmployee;

    /** Statuts du cycle (colonne `status`). */
    public const STATUS_EN_COURS  = 'en_cours';  // semé, en croissance
    public const STATUS_RECOLTE   = 'recolte';   // récolte en cours / partielle
    public const STATUS_TERMINE   = 'termine';   // cycle clos
    public const STATUS_ABANDONNE = 'abandonne'; // perte totale / abandon

    public const STATUS_ARCHIVED = [
        self::STATUS_TERMINE,
        self::STATUS_ABANDONNE,
    ];

    /**
     * Statuts « en cours » : le cycle occupe la parcelle et compte parmi les
     * cultures actives. RECOLTE en fait partie (cf. RecordHarvest qui y bascule
     * dès la première récolte, alors que la culture est toujours en place).
     * Source unique partagée par scopeInProgress, Plot::isOccupied et le
     * dashboard pour éviter toute divergence de définition d'« actif ».
     */
    public const IN_PROGRESS_STATUSES = [
        self::STATUS_EN_COURS,
        self::STATUS_RECOLTE,
    ];

    public const EDITABLE_STATUSES = [
        self::STATUS_EN_COURS,
        self::STATUS_RECOLTE,
        self::STATUS_TERMINE,
        self::STATUS_ABANDONNE,
    ];

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'farm_id', 'plot_id', 'campaign_id', 'crop_protocol_id', 'employee_id',
        'code', 'crop_name', 'variety', 'area_used_ha',
        'planting_date', 'expected_harvest_date', 'closing_date',
        'seed_quantity', 'seed_unit', 'expected_yield_kg',
        'status', 'total_acquisition_cost', 'additional_costs', 'total_revenue',
        'notes', 'photo_path',
    ];

    protected $casts = [
        'is_synced'              => 'boolean',
        'last_sync_at'           => 'datetime',
        'planting_date'          => 'date',
        'expected_harvest_date'  => 'date',
        'closing_date'           => 'date',
        'area_used_ha'           => 'decimal:4',
        'seed_quantity'          => 'decimal:3',
        'expected_yield_kg'      => 'decimal:3',
        'total_acquisition_cost' => 'decimal:2',
        'additional_costs'       => 'decimal:2',
        'total_revenue'          => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (CropCycle $cycle) {
            $cycle->status = $cycle->status ?? self::STATUS_EN_COURS;
        });
    }

    // ─── RELATIONS ───

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CropCampaign::class, 'campaign_id');
    }


    /** Protocole / itinéraire technique rattaché (optionnel). */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(CropProtocol::class, 'crop_protocol_id');
    }

    public function harvests(): HasMany
    {
        return $this->hasMany(Harvest::class);
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(CropInput::class);
    }

    /**
     * DÉLAI AVANT RÉCOLTE EN COURS (DAR) : traitement phytosanitaire dont le
     * délai de la notice n'est pas purgé — la récolte du cycle est interdite
     * (résidus). Renvoie l'intrant qui contraint le plus (échéance la plus
     * lointaine), null si le cycle est récoltable. Levée AUTOMATIQUE à la date.
     */
    public function activePreharvestInterval(?Carbon $harvestDate = null): ?CropInput
    {
        return $this->inputs()
            ->whereNotNull('preharvest_days')
            ->where('preharvest_days', '>', 0)
            ->get()
            ->filter(fn (CropInput $input) => $input->blocksHarvest($harvestDate))
            ->sortByDesc(fn (CropInput $input) => $input->harvest_allowed_from)
            ->first();
    }

    /** La récolte est-elle bloquée par un délai avant récolte ? */
    public function isHarvestBlocked(): bool
    {
        return $this->activePreharvestInterval() !== null;
    }

    /** Validations explicites d'étapes de l'itinéraire technique. */
    public function protocolCompletions(): HasMany
    {
        return $this->hasMany(CropProtocolCompletion::class);
    }

    // ─── SCOPES ───

    /** Cycles en cours (semés OU en récolte) — occupent la parcelle. */
    public function scopeInProgress($query)
    {
        return $query->whereIn('status', self::IN_PROGRESS_STATUSES);
    }

    public function scopeArchived($query)
    {
        return $query->whereIn('status', self::STATUS_ARCHIVED);
    }

    /**
     * Cycles arrivant à maturité : non archivés, dont la récolte prévue tombe
     * dans les `$daysAhead` jours (échéances passées comprises — retards).
     *
     * whereDate (et non comparaison de chaîne) : `expected_harvest_date` est
     * castée `date` mais stockée en datetime — une égalité de chaîne ne
     * matcherait jamais (bug récurrent du projet).
     */
    public function scopeDueForHarvest($query, int $daysAhead = 7)
    {
        return $query->whereIn('status', self::IN_PROGRESS_STATUSES)
            ->whereNotNull('expected_harvest_date')
            ->whereDate('expected_harvest_date', '<=', now()->addDays($daysAhead)->toDateString());
    }

    // ─── ÉTAT ───

    public function isActive(): bool
    {
        return $this->status === self::STATUS_EN_COURS;
    }

    public function isArchived(): bool
    {
        return in_array($this->status, self::STATUS_ARCHIVED, true);
    }

    // ─── ACCESSEURS ───

    /** Âge du cycle en jours (J1 = jour de semis). */
    public function getAgeAttribute(): int
    {
        if (! $this->planting_date) {
            return 0;
        }

        $start = Carbon::parse($this->planting_date)->startOfDay();
        $end = ($this->isArchived() && $this->closing_date)
            ? Carbon::parse($this->closing_date)->startOfDay()
            : now()->startOfDay();

        // Semis daté dans le futur → âge nul (et non un âge positif aberrant :
        // diffInDays renvoie une valeur absolue en Carbon 3).
        if ($end->lt($start)) {
            return 0;
        }

        return (int) $start->diffInDays($end) + 1;
    }

    /**
     * Poids total récolté en KG (toutes récoltes confondues). S'appuie sur le
     * poids agronomique effectif de chaque récolte (poids net pesé, ou quantité
     * si déjà en kg) — et reste donc juste même si certaines récoltes sont
     * saisies en caisses/sacs. Utilise la relation déjà chargée si elle l'est
     * (listes/dashboard l'eager-loadent) pour éviter un N+1.
     */
    public function getTotalHarvestedAttribute(): float
    {
        if ($this->relationLoaded('harvests')) {
            return (float) $this->harvests->sum->effective_weight_kg;
        }

        // Agrégat SQL : COALESCE(poids net, quantité si unité=kg, sinon 0).
        return (float) $this->harvests()
            ->sum(\Illuminate\Support\Facades\DB::raw(
                "COALESCE(net_weight_kg, CASE WHEN LOWER(unit) = 'kg' THEN quantity ELSE 0 END)"
            ));
    }

    /**
     * Rendement réel à l'hectare (kg/ha) sur la base de la surface emblavée,
     * calculé à partir du poids net pesé (total_harvested, toujours en kg).
     */
    public function getYieldPerHaAttribute(): float
    {
        $area = (float) $this->area_used_ha;
        if ($area <= 0) {
            return 0;
        }

        return round($this->total_harvested / $area, 2);
    }

    /**
     * Écart de rendement (%) : poids réellement récolté rapporté au rendement
     * attendu. > 0 = surperformance, < 0 = sous-performance. Null si aucun
     * rendement attendu n'a été renseigné (comparaison impossible).
     */
    public function getYieldGapPercentAttribute(): ?float
    {
        $expected = (float) $this->expected_yield_kg;
        if ($expected <= 0) {
            return null;
        }

        return round(($this->total_harvested - $expected) / $expected * 100, 1);
    }

    /**
     * Total des intrants itémisés rattachés au cycle (registre crop_inputs).
     */
    public function getInputsCostAttribute(): float
    {
        return (float) ($this->relationLoaded('inputs')
            ? $this->inputs->sum('total_cost')
            : $this->inputs()->sum('total_cost'));
    }

    /**
     * Coût TOTAL engagé sur le cycle : forfait d'acquisition + coûts
     * additionnels forfaitaires (main d'œuvre, irrigation…) + intrants
     * itémisés (registre crop_inputs). Les intrants détaillés viennent en
     * complément du forfait, pas en doublon : on saisit l'un OU l'autre.
     */
    public function getTotalCostAttribute(): float
    {
        return (float) $this->total_acquisition_cost
            + (float) $this->additional_costs
            + $this->inputs_cost;
    }

    /**
     * COÛT DES MARCHANDISES VENDUES (T1).
     *
     * = coût total engagé − coût des kg SORTIS DU CYCLE sans être vendus.
     *
     * Une récolte séchée ou stockée pour vendre plus tard quitte le cycle en
     * matière : son coût la suit dans l'inventaire (où RecordHarvest la valorise
     * déjà au coût de production). L'imputer au cycle afficherait une marge
     * catastrophique le mois de la récolte, puis un profit sans coût le mois de
     * la vente — deux mensonges qui se compensent, et aucun pilotage possible.
     */
    public function costOfGoodsSold(): float
    {
        return round(max(0.0, $this->total_cost - $this->heldValorisation()['value']), 2);
    }

    /**
     * Récoltes CONSERVÉES (à transformer / stockées) : poids et valeur AU COÛT
     * DE PRODUCTION. Volontairement au coût, jamais au prix espéré : ce n'est
     * pas un profit tant que ce n'est pas vendu.
     *
     * @return array{kg: float, value: float}
     */
    public function heldValorisation(): array
    {
        $kg = (float) $this->harvests()
            ->held()
            ->sum(\Illuminate\Support\Facades\DB::raw(
                "COALESCE(net_weight_kg, CASE WHEN LOWER(unit) = 'kg' THEN quantity ELSE 0 END)"
            ));

        return ['kg' => $kg, 'value' => round($kg * $this->productionCostPerKg(), 2)];
    }

    /**
     * Marge nette RÉALISÉE du cycle : revenus encaissés − coût des marchandises
     * vendues. Elle ne bouge plus quand la récolte conservée est vendue plus
     * tard — cette vente-là est une vente de stock, avec sa propre marge
     * (prix − CMP), ce qui est le traitement correct.
     *
     * Rétro-compatibilité : sans récolte conservée, heldValorisation vaut 0 et
     * la formule redevient exactement l'ancienne.
     */
    public function getNetMarginAttribute(): float
    {
        return round((float) $this->total_revenue - $this->costOfGoodsSold(), 2);
    }

    /**
     * Coût de production par KG récolté du cycle (base de valorisation de
     * l'inventaire « recoltes »).
     *
     * = (coût d'acquisition forfaitaire + coûts additionnels + intrants
     * itémisés) ÷ poids total récolté (kg effectifs). Renvoie 0 si rien n'a
     * encore été récolté (valorisation impossible / division par zéro).
     */
    public function productionCostPerKg(): float
    {
        $kg = $this->total_harvested;
        if ($kg <= 0) {
            return 0.0;
        }

        return round($this->total_cost / $kg, 2);
    }

    /**
     * Recalcule total_revenue depuis les récoltes RÉELLEMENT VENDUES (T1).
     *
     * Base = POIDS NET EFFECTIF (kg) × prix unitaire (exprimé au kg), cohérent
     * avec le rendement (kg/ha) et la valorisation de l'inventaire. Sommer
     * quantity × prix mélangerait des unités hétérogènes (caisses, sacs, kg)
     * et fausserait le revenu dès qu'une récolte n'est pas saisie en kg.
     *
     * FILTRE `sold()` : une récolte destinée au séchage ou au stockage n'a rien
     * encaissé. La compter inscrivait un revenu fictif au cours (bas) du jour de
     * récolte — précisément ce que le report de vente cherche à éviter.
     */
    public function recalculateRevenue(): void
    {
        $revenue = $this->harvests()
            ->sold()
            ->selectRaw(
                "COALESCE(SUM(COALESCE(net_weight_kg, CASE WHEN LOWER(unit) = 'kg' THEN quantity ELSE 0 END) * COALESCE(unit_price, 0)), 0) as total"
            )
            ->value('total') ?? 0;

        $this->updateQuietly(['total_revenue' => (float) $revenue]);
    }
}
