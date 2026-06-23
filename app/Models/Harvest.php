<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;

/**
 * Récolte (module Production Végétale).
 *
 * Une récolte est rattachée à un cycle de culture. Elle peut, en option,
 * alimenter le stock (catégorie « recoltes ») via l'action RecordHarvest.
 */
class Harvest extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    /** Qualité de la récolte (colonne `quality`). */
    public const QUALITY_BON      = 'bon';
    public const QUALITY_MOYEN    = 'moyen';
    public const QUALITY_MEDIOCRE = 'mediocre';

    public const QUALITIES = [
        self::QUALITY_BON,
        self::QUALITY_MOYEN,
        self::QUALITY_MEDIOCRE,
    ];

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'farm_id', 'crop_cycle_id', 'employee_id',
        'harvest_date', 'quantity', 'unit', 'net_weight_kg', 'loss_quantity', 'quality',
        'synced_to_stock', 'stock_item_name', 'unit_price', 'notes',
    ];

    protected $casts = [
        'is_synced'       => 'boolean',
        'last_sync_at'    => 'datetime',
        'harvest_date'    => 'date',
        'quantity'        => 'decimal:3',
        'net_weight_kg'   => 'decimal:3',
        'loss_quantity'   => 'decimal:3',
        'unit_price'      => 'decimal:2',
        'synced_to_stock' => 'boolean',
    ];

    // ─── RELATIONS ───

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ─── ACCESSEURS ───

    /** Valeur estimée de la récolte (quantité × prix unitaire). */
    public function getEstimatedValueAttribute(): float
    {
        return round((float) $this->quantity * (float) ($this->unit_price ?? 0), 2);
    }

    /**
     * Poids agronomique effectif (kg) : le poids net pesé s'il est saisi, sinon
     * la quantité quand elle est déjà exprimée en kg. Source unique des KPI de
     * rendement, robuste même si la récolte est saisie dans une autre unité.
     */
    public function getEffectiveWeightKgAttribute(): float
    {
        if ($this->net_weight_kg !== null) {
            return (float) $this->net_weight_kg;
        }

        return strtolower((string) $this->unit) === 'kg' ? (float) $this->quantity : 0.0;
    }
}
