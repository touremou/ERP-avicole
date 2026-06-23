<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;

/**
 * Parcelle agricole (module Production Végétale).
 *
 * Équivalent fonctionnel du `Building` côté élevage, mais pour les cultures :
 * une parcelle accueille successivement des cycles de culture (assolement).
 */
class Plot extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    /** Statuts du cycle de vie d'une parcelle (colonne `status`). */
    public const STATUS_DISPONIBLE = 'disponible'; // libre, prête à semer
    public const STATUS_EN_CULTURE = 'en_culture'; // un cycle est en cours
    public const STATUS_JACHERE    = 'jachere';    // au repos
    public const STATUS_INACTIVE   = 'inactive';   // hors exploitation

    public const STATUSES = [
        self::STATUS_DISPONIBLE,
        self::STATUS_EN_CULTURE,
        self::STATUS_JACHERE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'farm_id', 'code', 'name', 'area_ha', 'location',
        'soil_type', 'agro_zone', 'irrigation_type', 'status', 'notes',
    ];

    /** Correspondance région administrative guinéenne → zone agro-écologique. */
    private const REGION_ZONE_MAP = [
        'conakry'    => 'basse_guinee',
        'kindia'     => 'basse_guinee',
        'boke'       => 'basse_guinee',
        'dubreka'    => 'basse_guinee',
        'mamou'      => 'moyenne_guinee',
        'labe'       => 'moyenne_guinee',
        'kankan'     => 'haute_guinee',
        'faranah'    => 'haute_guinee',
        'siguiri'    => 'haute_guinee',
        'kouroussa'  => 'haute_guinee',
        'nzerekore'  => 'guinee_forestiere',
        'macenta'    => 'guinee_forestiere',
        'gueckedou'  => 'guinee_forestiere',
        'kissidougou' => 'guinee_forestiere',
    ];

    protected $casts = [
        'is_synced'    => 'boolean',
        'last_sync_at' => 'datetime',
        'area_ha'      => 'decimal:4',
    ];

    // ─── RELATIONS ───

    public function cropCycles(): HasMany
    {
        return $this->hasMany(CropCycle::class);
    }

    /** Cycle de culture le plus récent actuellement en cours sur la parcelle. */
    public function activeCycle(): HasOne
    {
        return $this->hasOne(CropCycle::class)
            ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
            ->latestOfMany('planting_date');
    }

    // ─── SCOPES ───

    public function scopeAvailable($query)
    {
        return $query->whereIn('status', [self::STATUS_DISPONIBLE, self::STATUS_JACHERE]);
    }

    // ─── ÉTAT ───

    /**
     * La parcelle a-t-elle un cycle de culture en cours ? Inclut la phase de
     * récolte (RECOLTE) : la culture est toujours en place. Garde-fou contre
     * la suppression d'une parcelle dont un cycle est encore actif (la FK
     * plot_id est cascadeOnDelete — sinon perte du cycle et de ses récoltes).
     */
    public function isOccupied(): bool
    {
        return $this->cropCycles()
            ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
            ->exists();
    }

    // ─── ZONE AGRO-ÉCOLOGIQUE ───

    /**
     * Zone agro-écologique effective : la zone explicite de la parcelle si
     * renseignée, sinon celle déduite de la région de la ferme.
     */
    public function resolvedAgroZone(): ?string
    {
        return $this->agro_zone ?: self::zoneFromRegion($this->farm?->region);
    }

    /**
     * Déduit la zone agro-écologique d'une région administrative guinéenne.
     * Insensible à la casse, aux accents et aux espaces. Retourne null si inconnue.
     */
    public static function zoneFromRegion(?string $region): ?string
    {
        if (! $region) {
            return null;
        }

        $key = trim($region);
        // Translittération ASCII (Labé → labe, Nzérékoré → nzerekore…).
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $key);
        if ($ascii !== false) {
            $key = preg_replace('/[^a-zA-Z]/', '', $ascii);
        }
        $key = strtolower($key);

        return self::REGION_ZONE_MAP[$key] ?? null;
    }
}
