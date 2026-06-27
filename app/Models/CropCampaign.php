<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;

/**
 * Campagne agricole (module Production Végétale).
 *
 * Regroupe les cycles de culture d'une même saison culturale pour piloter
 * objectif vs réalisé à l'échelle de l'exploitation. Saisons calées sur le
 * climat guinéen (cf. SEASONS).
 */
class CropCampaign extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    public const STATUS_PLANIFIEE = 'planifiee';
    public const STATUS_EN_COURS  = 'en_cours';
    public const STATUS_CLOTUREE  = 'cloturee';

    public const STATUSES = [
        self::STATUS_PLANIFIEE => 'Planifiée',
        self::STATUS_EN_COURS  => 'En cours',
        self::STATUS_CLOTUREE  => 'Clôturée',
    ];

    /** Saisons agricoles guinéennes (label + mois + couleur d'affichage). */
    public const SEASONS = [
        'grande_saison_pluies' => ['label' => 'Grande saison des pluies', 'months' => 'mai – oct.',  'color' => 'green'],
        'petite_saison'        => ['label' => 'Petite saison',            'months' => 'nov. – déc.', 'color' => 'sky'],
        'saison_seche'         => ['label' => 'Saison sèche',             'months' => 'janv. – avr.', 'color' => 'amber'],
    ];

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'farm_id', 'code', 'name', 'year', 'season',
        'start_date', 'end_date_planned', 'target_production_t', 'status', 'notes',
    ];

    protected $casts = [
        'is_synced'           => 'boolean',
        'last_sync_at'        => 'datetime',
        'year'                => 'integer',
        'start_date'          => 'date',
        'end_date_planned'    => 'date',
        'target_production_t' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (CropCampaign $c) {
            $c->status = $c->status ?? self::STATUS_PLANIFIEE;
        });
    }

    // ─── RELATIONS ───

    public function cycles(): HasMany
    {
        return $this->hasMany(CropCycle::class, 'campaign_id');
    }

    // ─── SCOPES ───

    public function scopeForYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    // ─── ACCESSEURS ───

    public function getSeasonLabelAttribute(): string
    {
        return self::SEASONS[$this->season]['label'] ?? ucfirst((string) $this->season);
    }

    public function getSeasonColorAttribute(): string
    {
        return self::SEASONS[$this->season]['color'] ?? 'slate';
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /**
     * Total récolté (kg) sur tous les cycles de la campagne. Utilise la relation
     * déjà chargée si elle l'est, sinon agrège en base (évite le N+1).
     */
    public function getTotalHarvestedAttribute(): float
    {
        if ($this->relationLoaded('cycles')) {
            return (float) $this->cycles->sum(fn ($c) => $c->total_harvested);
        }

        // Même base que CropCycle::getTotalHarvestedAttribute : poids net pesé,
        // ou quantité seulement si la récolte est déjà en kg. Sommer `quantity`
        // brut mélangerait des unités hétérogènes (caisses, sacs, kg).
        return (float) Harvest::whereIn(
            'crop_cycle_id',
            CropCycle::where('campaign_id', $this->id)->select('id')
        )->sum(\Illuminate\Support\Facades\DB::raw(
            "COALESCE(net_weight_kg, CASE WHEN LOWER(unit) = 'kg' THEN quantity ELSE 0 END)"
        ));
    }

    /** Avancement vers l'objectif de production (%), borné à 100. */
    public function getProgressPercentAttribute(): ?float
    {
        $target = (float) $this->target_production_t;
        if ($target <= 0) {
            return null;
        }

        return min(100, round(($this->total_harvested / 1000) / $target * 100, 1));
    }
}
