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
        'input_date', 'preharvest_days', 'synced_to_stock', 'stock_item_name', 'notes',
    ];

    protected $casts = [
        'is_synced'       => 'boolean',
        'last_sync_at'    => 'datetime',
        'input_date'      => 'date',
        'preharvest_days' => 'integer',
        'quantity'        => 'decimal:3',
        'unit_cost'       => 'decimal:2',
        'total_cost'      => 'decimal:2',
        'synced_to_stock' => 'boolean',
    ];

    // ─── DÉLAI AVANT RÉCOLTE (DAR — résidus phytosanitaires) ───

    /**
     * Date de FIN du délai avant récolte : à partir de ce jour, la production
     * du cycle est récoltable. Null si l'intrant n'impose aucun délai
     * (engrais, irrigation, main d'œuvre…).
     */
    public function getHarvestAllowedFromAttribute(): ?\Carbon\Carbon
    {
        if (! $this->preharvest_days || ! $this->input_date) {
            return null;
        }

        return $this->input_date->copy()->addDays((int) $this->preharvest_days);
    }

    /** Le délai avant récolte court-il encore aujourd'hui ? */
    public function blocksHarvest(): bool
    {
        $from = $this->harvest_allowed_from;

        return $from !== null && $from->isAfter(now()->startOfDay());
    }

    /** Jours restants avant récolte autorisée (0 si purgé/sans délai). */
    public function getPreharvestDaysLeftAttribute(): int
    {
        $from = $this->harvest_allowed_from;

        return $from === null ? 0 : max(0, now()->startOfDay()->diffInDays($from, false));
    }

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
