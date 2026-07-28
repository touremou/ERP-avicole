<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;
use App\Traits\ReferencesEmployee;
use App\Traits\HasStandardUuid;

/**
 * Récolte (module Production Végétale).
 *
 * Une récolte est rattachée à un cycle de culture. Elle peut, en option,
 * alimenter le stock (catégorie « recoltes ») via l'action RecordHarvest.
 */
class Harvest extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm, ReferencesEmployee;

    /** Qualité de la récolte (colonne `quality`). */
    public const QUALITY_BON      = 'bon';
    public const QUALITY_MOYEN    = 'moyen';
    public const QUALITY_MEDIOCRE = 'mediocre';

    public const QUALITIES = [
        self::QUALITY_BON,
        self::QUALITY_MOYEN,
        self::QUALITY_MEDIOCRE,
    ];

    /**
     * Destination de la récolte (colonne `destination`) — T1.
     *
     * Décide si la récolte est un REVENU ou un STOCK. Une récolte vendue porte
     * un prix encaissé ; une récolte séchée ou mise de côté pour vendre plus
     * cher plus tard ne porte AUCUN revenu tant qu'elle n'est pas vendue : elle
     * quitte le cycle en matière (vers l'inventaire) et son coût quitte le
     * cycle avec elle.
     */
    public const DEST_VENTE          = 'vente';
    public const DEST_TRANSFORMATION = 'transformation';
    public const DEST_STOCKAGE       = 'stockage';

    public const DESTINATIONS = [
        self::DEST_VENTE          => 'Vendue',
        self::DEST_TRANSFORMATION => 'À transformer',
        self::DEST_STOCKAGE       => 'Stockée (vente différée)',
    ];

    /** Destinations qui NE génèrent pas de revenu : la matière est conservée. */
    public const DEST_HELD = [
        self::DEST_TRANSFORMATION,
        self::DEST_STOCKAGE,
    ];

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'farm_id', 'crop_cycle_id', 'employee_id',
        'harvest_date', 'quantity', 'unit', 'net_weight_kg', 'loss_quantity', 'quality',
        'destination',
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

    // ─── SCOPES ───

    /** Récoltes effectivement VENDUES : la seule base du revenu du cycle. */
    public function scopeSold($query)
    {
        return $query->where('destination', self::DEST_VENTE);
    }

    /**
     * Récoltes CONSERVÉES (à transformer ou stockées) : pas de revenu, mais de
     * la matière et du coût sortis du cycle vers l'inventaire.
     */
    public function scopeHeld($query)
    {
        return $query->whereIn('destination', self::DEST_HELD);
    }

    /**
     * Récoltes descendues à l'atelier (T1) : destinées à la transformation et
     * PAS ENCORE transformées, sur une fenêtre récente. Une récolte déjà séchée
     * ne doit plus apparaître — l'opérateur ne peut pas engager deux fois la
     * même matière.
     */
    public function scopeAwaitingTransformationForSync($query)
    {
        return $query->where('destination', self::DEST_TRANSFORMATION)
            ->whereDoesntHave('transformations')
            ->whereDate('harvest_date', '>=', now()->subDays(60)->toDateString());
    }

    // ─── RELATIONS ───

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }


    /**
     * Lots de transformation issus de cette récolte (T1) — traçabilité
     * descendante : « où est parti le gombo du 12 août ? ».
     */
    public function transformations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CropTransformation::class);
    }

    // ─── ACCESSEURS ───

    /**
     * Valeur estimée de la récolte = poids net effectif (kg) × prix unitaire
     * (au kg), cohérent avec total_revenue et la valorisation de l'inventaire.
     */
    public function getEstimatedValueAttribute(): float
    {
        return round($this->effective_weight_kg * (float) ($this->unit_price ?? 0), 2);
    }

    public function getDestinationLabelAttribute(): string
    {
        return self::DESTINATIONS[$this->destination] ?? self::DESTINATIONS[self::DEST_VENTE];
    }

    /** La récolte est-elle conservée (donc hors revenu du cycle) ? */
    public function getIsHeldAttribute(): bool
    {
        return in_array($this->destination, self::DEST_HELD, true);
    }

    /**
     * Poids agronomique effectif (kg) : le poids net pesé s'il est saisi, sinon
     * la quantité quand elle est déjà exprimée en kg. Source unique des KPI de
     * rendement, robuste même si la récolte est saisie dans une autre unité.
     */
    public function getEffectiveWeightKgAttribute(): float
    {
        return self::effectiveWeightKgFrom($this->net_weight_kg, $this->unit, $this->quantity);
    }

    /**
     * Poids effectif (kg) à partir de valeurs brutes — utile pour réévaluer un
     * mouvement de stock depuis les valeurs d'origine (réconciliation observer).
     */
    public static function effectiveWeightKgFrom($netWeightKg, $unit, $quantity): float
    {
        if ($netWeightKg !== null && $netWeightKg !== '') {
            return (float) $netWeightKg;
        }

        return strtolower((string) $unit) === 'kg' ? (float) $quantity : 0.0;
    }
}
