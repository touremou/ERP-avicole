<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'soil_type', 'irrigation_type', 'status', 'notes',
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

    /** Cycle de culture actuellement en cours sur la parcelle (le plus récent). */
    public function activeCycle(): HasMany
    {
        return $this->hasMany(CropCycle::class)
            ->where('status', CropCycle::STATUS_EN_COURS)
            ->latest('planting_date');
    }

    // ─── SCOPES ───

    public function scopeAvailable($query)
    {
        return $query->whereIn('status', [self::STATUS_DISPONIBLE, self::STATUS_JACHERE]);
    }

    // ─── ÉTAT ───

    /** La parcelle a-t-elle un cycle de culture en cours ? */
    public function isOccupied(): bool
    {
        return $this->cropCycles()
            ->where('status', CropCycle::STATUS_EN_COURS)
            ->exists();
    }
}
