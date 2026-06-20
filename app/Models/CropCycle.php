<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use App\Traits\BelongsToFarm;
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
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

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
        'farm_id', 'plot_id', 'campaign_id', 'employee_id',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function harvests(): HasMany
    {
        return $this->hasMany(Harvest::class);
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(CropInput::class);
    }

    // ─── SCOPES ───

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_EN_COURS);
    }

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

        return (int) $start->diffInDays($end) + 1;
    }

    /**
     * Quantité totale récoltée (toutes récoltes confondues), dans l'unité des
     * récoltes (kg par convention). Utilise la relation déjà chargée si elle
     * l'est (listes/dashboard l'eager-loadent) pour éviter un N+1.
     */
    public function getTotalHarvestedAttribute(): float
    {
        return (float) ($this->relationLoaded('harvests')
            ? $this->harvests->sum('quantity')
            : $this->harvests()->sum('quantity'));
    }

    /**
     * Rendement réel à l'hectare (kg/ha) sur la base de la surface emblavée.
     * Hypothèse : les récoltes sont saisies en kg (unité par défaut).
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
     * Total des intrants itémisés rattachés au cycle (registre crop_inputs).
     */
    public function getInputsCostAttribute(): float
    {
        return (float) ($this->relationLoaded('inputs')
            ? $this->inputs->sum('total_cost')
            : $this->inputs()->sum('total_cost'));
    }

    /**
     * Marge nette consolidée du cycle (revenus − coûts).
     *
     * Même esprit que Batch::getNetMarginAttribute : revenus enregistrés moins
     * coûts d'acquisition (forfait initial), coûts additionnels forfaitaires
     * (main d'œuvre, irrigation…) ET intrants itémisés (registre crop_inputs).
     * Les intrants détaillés viennent en complément du forfait, pas en doublon :
     * on saisit l'un OU l'autre selon le niveau de détail souhaité.
     */
    public function getNetMarginAttribute(): float
    {
        return (float) $this->total_revenue
            - (float) $this->total_acquisition_cost
            - (float) $this->additional_costs
            - $this->inputs_cost;
    }
}
