<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;

/**
 * Intrant de culture (module Production Végétale).
 *
 * Ligne de charge itémisée rattachée à un cycle de culture. Pendant végétal de
 * `FeedPurchase` : alimente le calcul de la marge nette du cycle.
 */
class CropInput extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    /** Catégories d'intrants (colonne `type`). */
    public const TYPES = [
        'semence'      => 'Semence',
        'engrais'      => 'Engrais',
        'phyto'        => 'Produit phytosanitaire',
        'irrigation'   => 'Irrigation',
        'main_doeuvre' => "Main d'œuvre",
        'carburant'    => 'Carburant',
        'autre'        => 'Autre',
    ];

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'farm_id', 'crop_cycle_id', 'provider_id',
        'type', 'name', 'quantity', 'unit', 'unit_cost', 'total_cost',
        'input_date', 'synced_to_stock', 'stock_item_name', 'notes',
    ];

    protected $casts = [
        'is_synced'       => 'boolean',
        'last_sync_at'    => 'datetime',
        'input_date'      => 'date',
        'quantity'        => 'decimal:3',
        'unit_cost'       => 'decimal:2',
        'total_cost'      => 'decimal:2',
        'synced_to_stock' => 'boolean',
    ];

    // ─── RELATIONS ───

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    // ─── ACCESSEURS ───

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }
}
