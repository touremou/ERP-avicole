<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToFarm;

class Formula extends Model
{
    use HasFactory, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'name',
        'code',
        'target_type',
        'species_id',
        'production_type_id',
        'total_batch_weight',
        'description',
        'is_active'
    ];

    protected $casts = [
        'total_batch_weight' => 'decimal:2',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    /**
     * Liaison avec les lignes d'ingrédients
     */
    public function items(): HasMany
    {
        return $this->hasMany(FormulaItem::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function productionType(): BelongsTo
    {
        return $this->belongsTo(ProductionType::class);
    }

    /**
     * Secteur d'aliment produit par cette formule (cf. Batch::FEED_PHASES),
     * dérivé du type de production rattaché. À défaut (formules legacy sans
     * production_type_id), on retombe sur la colonne `poultry_type`
     * (Chair/Ponte) puis sur « Chair ».
     */
    public function feedSector(): string
    {
        if ($this->productionType) {
            return $this->productionType->feedSector();
        }

        return in_array($this->poultry_type, array_keys(Batch::FEED_PHASES), true)
            ? $this->poultry_type
            : 'Chair';
    }

    /**
     * Liaison avec les ordres de fabrication (Production)
     * Utile pour vérifier si la formule est utilisée avant suppression
     */
    public function productions(): HasMany
    {
        return $this->hasMany(MillProduction::class);
    }

    // -----------------------
    // ACCESSEURS (KPI NUTRITIONNELS & FINANCIERS)
    // -----------------------

    /**
     * Coût de revient théorique au KG (Basé sur les derniers prix d'achat)
     * Indispensable pour l'arbitrage économique des recettes
     */
    public function getCostPerKgAttribute(): float
    {
        $totalCost = $this->items->sum(function($item) {
            return ($item->percentage / 100) * ($item->rawMaterial->unit_cost ?? 0);
        });

        return round((float) $totalCost, 2);
    }

    /**
     * Coût total pour une gâchée (Batch) complète
     */
    public function getTotalBatchCostAttribute(): float
    {
        return round($this->cost_per_kg * ($this->total_batch_weight ?? 1000), 2);
    }

    /**
     * Analyse nutritionnelle complète consolidée
     * Rigueur : Centralisation du calcul pour éviter la dispersion dans les controllers
     */
    public function getNutritionalProfileAttribute(): array
    {
        $profile = ['em' => 0.0, 'pb' => 0.0, 'lys' => 0.0, 'ca' => 0.0, 'p' => 0.0];

        foreach ($this->items as $item) {
            $ratio = (float) ($item->percentage / 100);
            $rm = $item->rawMaterial;

            if ($rm) {
                $profile['em']  += $ratio * (float) $rm->energy_kcal;
                $profile['pb']  += $ratio * (float) $rm->protein_rate;
                $profile['lys'] += $ratio * (float) $rm->lysine_rate;
                $profile['ca']  += $ratio * (float) $rm->calcium_rate;
            }
        }

        return array_map(fn($v) => round($v, 3), $profile);
    }

    // -----------------------
    // LOGIQUE MÉTIER
    // -----------------------

    /**
     * Calcule la quantité nécessaire de chaque ingrédient pour un poids cible donné
     */
    public function calculateRequirementsForWeight(float $targetWeight): array
    {
        return $this->items->map(function($item) use ($targetWeight) {
            return [
                'material' => $item->rawMaterial->name,
                'needed_kg' => round(($item->percentage / 100) * $targetWeight, 2),
            ];
        })->toArray();
    }
}