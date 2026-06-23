<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;

/**
 * Transformation végétale (module Production Végétale).
 *
 * Pendant végétal de `Transformation` (abattoir) : une opération entrée→sortie
 * qui convertit une matière première agricole en produit fini (gari, farine,
 * jus, fruits séchés…), avec rendement et péremption.
 */
class CropTransformation extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    public const STATUS_EN_COURS = 'en_cours';
    public const STATUS_TERMINE  = 'termine';

    /** Types courants d'agro-transformation (libellés FR pour l'affichage). */
    public const TYPES = [
        'sechage'      => 'Séchage',
        'mouture'      => 'Mouture / Farine',
        'jus'          => 'Jus / Pressage',
        'fermentation' => 'Fermentation',
        'torrefaction' => 'Torréfaction',
        'conserverie'  => 'Conserverie',
        'autre'        => 'Autre',
    ];

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'farm_id', 'crop_cycle_id', 'crop_recipe_id', 'employee_id',
        'batch_number', 'input_product', 'output_product', 'transformation_type',
        'input_quantity', 'input_unit', 'output_quantity', 'output_unit', 'yield_percent',
        'production_date', 'expiry_date',
        'production_cost', 'output_unit_price',
        'consumed_from_stock', 'input_stock_item', 'synced_to_stock', 'output_stock_item',
        'status', 'notes',
    ];

    protected $casts = [
        'is_synced'           => 'boolean',
        'last_sync_at'        => 'datetime',
        'production_date'     => 'date',
        'expiry_date'         => 'date',
        'input_quantity'      => 'decimal:3',
        'output_quantity'     => 'decimal:3',
        'yield_percent'       => 'decimal:2',
        'production_cost'     => 'decimal:2',
        'output_unit_price'   => 'decimal:2',
        'consumed_from_stock' => 'boolean',
        'synced_to_stock'     => 'boolean',
    ];

    // ─── RELATIONS ───

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(CropRecipe::class, 'crop_recipe_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ─── ACCESSEURS ───

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->transformation_type] ?? ucfirst((string) $this->transformation_type);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    /** Valeur estimée du produit fini (sortie × prix unitaire). */
    public function getEstimatedValueAttribute(): float
    {
        return round((float) $this->output_quantity * (float) ($this->output_unit_price ?? 0), 2);
    }

    // ─── NUMÉROTATION ───

    public static function generateBatchNumber(): string
    {
        $year = now()->format('Y');
        $last = static::withoutGlobalScopes()
            ->where('batch_number', 'LIKE', "TRV-{$year}-%")
            ->max('batch_number');
        $seq = $last ? (int) substr($last, -6) + 1 : 1;

        return sprintf('TRV-%s-%06d', $year, $seq);
    }
}
