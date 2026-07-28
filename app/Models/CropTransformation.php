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
 * Transformation végétale (module Production Végétale).
 *
 * Pendant végétal de `Transformation` (abattoir) : une opération entrée→sortie
 * qui convertit une matière première agricole en produit fini (gari, farine,
 * jus, fruits séchés…), avec rendement et péremption.
 */
class CropTransformation extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm, ReferencesEmployee;

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
        'farm_id', 'crop_cycle_id', 'harvest_id', 'crop_recipe_id', 'employee_id',
        'batch_number', 'input_product', 'output_product', 'transformation_type',
        'input_quantity', 'input_unit', 'output_quantity', 'output_unit', 'yield_percent',
        'production_date', 'expiry_date',
        'production_cost', 'input_cost', 'output_unit_cost', 'output_unit_price',
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
        'input_cost'          => 'decimal:2',
        'output_unit_cost'    => 'decimal:2',
        'output_unit_price'   => 'decimal:2',
        'consumed_from_stock' => 'boolean',
        'synced_to_stock'     => 'boolean',
    ];

    // ─── RELATIONS ───

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    /**
     * Récolte précise engagée (T1) — traçabilité au lot. Un sac de gombo séché
     * vendu quatre mois plus tard doit pouvoir remonter à SA récolte, pas
     * seulement au cycle.
     */
    public function harvest(): BelongsTo
    {
        return $this->belongsTo(Harvest::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(CropRecipe::class, 'crop_recipe_id');
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

    /** Valeur estimée du produit fini au prix de vente VISÉ (sortie × prix). */
    public function getEstimatedValueAttribute(): float
    {
        return round((float) $this->output_quantity * (float) ($this->output_unit_price ?? 0), 2);
    }

    /**
     * Coût de revient TOTAL du lot transformé (T1) = matière première engagée
     * + coût de l'opération (main d'œuvre, bois/gaz, emballage).
     */
    public function getTotalCostAttribute(): float
    {
        return round((float) ($this->input_cost ?? 0) + (float) ($this->production_cost ?? 0), 2);
    }

    /**
     * MARGE ATTENDUE si le lot est vendu au prix visé. C'est le chiffre qui
     * justifie (ou non) de sécher plutôt que de vendre frais : négatif, le
     * séchage détruit de la valeur malgré un prix de vente plus élevé au kg,
     * parce qu'il faut ~10 kg de frais pour 1 kg de sec.
     */
    public function getExpectedMarginAttribute(): ?float
    {
        if ($this->output_unit_price === null) {
            return null;
        }

        return round($this->estimated_value - $this->total_cost, 2);
    }

    /** Marge attendue en % du coût de revient (null si coût inconnu). */
    public function getExpectedMarginPercentAttribute(): ?float
    {
        $cost = $this->total_cost;
        if ($cost <= 0 || $this->expected_margin === null) {
            return null;
        }

        return round($this->expected_margin / $cost * 100, 1);
    }

    // ─── NUMÉROTATION ───

    public static function generateBatchNumber(): string
    {
        return \App\Services\DocumentNumberingService::generate('crop_transformation');
    }
}
