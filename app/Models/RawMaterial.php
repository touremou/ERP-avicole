<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToFarm;

class RawMaterial extends Model
{
    use HasFactory, BelongsToFarm;

    /**
     * D'où viennent les teneurs : la table de RÉFÉRENCE livrée (estimations,
     * ReferentialSeeder::MATIERES) ou une SAISIE au laboratoire (analyse,
     * fiche fournisseur). Une saisie n'est jamais écrasée par la référence.
     */
    public const SOURCE_REFERENCE = 'reference';
    public const SOURCE_SAISIE    = 'saisie';

    protected $fillable = [
        'farm_id',
        'name', 
        'unit', 
        'stock_qty', 
        'unit_cost', 
        'stock_id',
        'energy_kcal', 
        'protein_rate', 
        'lysine_rate', 
        'methionine_rate',
        'calcium_rate',
        'phosphorus_rate',
        'nutrition_source',
        'alert_threshold', 
        'is_active'
    ];

    /**
     * Rigueur ERP : Le casting est crucial pour éviter les erreurs d'arrondi 
     * lors des calculs de formulation nutritionnelle.
     */
    protected $casts = [
        'stock_qty'       => 'decimal:3', // Précision au gramme
        'unit_cost'       => 'decimal:2',
        'energy_kcal'     => 'decimal:2',
        'protein_rate'    => 'decimal:2',
        'lysine_rate'     => 'decimal:3', // Les acides aminés sont précis à 3 décimales
        'methionine_rate' => 'decimal:3',
        'calcium_rate'    => 'decimal:2',
        'phosphorus_rate' => 'decimal:3',
        'alert_threshold' => 'decimal:2',
        'is_active'       => 'boolean',
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    /**
     * Liaison avec le catalogue de stock général si nécessaire.
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Relation avec les lignes de formules (Recettes).
     */
    public function formulaItems(): HasMany
    {
        return $this->hasMany(FormulaItem::class);
    }

    // -----------------------
    // ACCESSEURS (KPI & VIGILANCE)
    // -----------------------

    /**
     * Valorisation financière instantanée du stock (CMP * Quantité).
     */
    public function getTotalStockValueAttribute(): float
    {
        return (float) ($this->stock_qty * $this->unit_cost);
    }

    /**
     * Indicateur de stock bas.
     */
    public function getIsLowStockAttribute(): bool
    {
        if (!$this->alert_threshold) return false;
        return $this->stock_qty <= $this->alert_threshold;
    }

    /**
     * Label nutritionnel complet pour les selects de formulation.
     */
    public function getNutritionalSummaryAttribute(): string
    {
        // Une teneur vide est « non analysée » : on l'écrit « — », pas « % ».
        $em = $this->energy_kcal ?? '—';
        $pb = $this->protein_rate !== null ? "{$this->protein_rate}%" : '—';

        return "{$this->name} ({$em} kcal | {$pb} Prot.)";
    }

    // -----------------------
    // SCOPES
    // -----------------------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCritical($query)
    {
        return $query->whereColumn('stock_qty', '<=', 'alert_threshold');
    }
}