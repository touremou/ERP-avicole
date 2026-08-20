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
     * Analyse nutritionnelle pondérée du mélange — LA seule implémentation.
     *
     * Elle vivait ici en pièce détachée (quatre nutriments, dont un jamais
     * calculé) pendant que la liste, la fiche et le formulaire de création
     * recalculaient chacun leur propre version en deux nutriments. Les écrans
     * lisent maintenant ceci.
     *
     * @return array<string, float> teneur du mélange par clef de nutriment
     */
    public function getNutritionalProfileAttribute(): array
    {
        $profile = [];

        foreach (FoodNorm::NUTRIENTS as $key => $nutrient) {
            $total = 0.0;

            foreach ($this->items as $item) {
                $ratio = (float) $item->percentage / 100;
                $total += $ratio * (float) ($item->rawMaterial->{$nutrient['material']} ?? 0);
            }

            $profile[$key] = round($total, 3);
        }

        return $profile;
    }

    /** Mémo de résolution de la norme (false = pas encore résolue). */
    protected FoodNorm|null|false $resolvedNorm = false;

    /**
     * Norme applicable, résolue une seule fois par instance.
     */
    public function norm(): ?FoodNorm
    {
        if ($this->resolvedNorm === false) {
            $this->resolvedNorm = FoodNorm::resolveFor($this->target_type);
        }

        return $this->resolvedNorm;
    }

    /**
     * Rattache leur norme à une liste de formules en UNE requête.
     *
     * @param  iterable<self>  $formulas
     */
    public static function attachNorms(iterable $formulas): void
    {
        $index = FoodNorm::indexByAnimalType();

        foreach ($formulas as $formula) {
            $formula->resolvedNorm = $index->get($formula->target_type);
        }
    }

    /**
     * Normes du référentiel qui pourraient s'appliquer.
     *
     * `food_norms` est clefée sur (animal_type, phase) : un fichier renseigné de
     * la manière naturelle — « chair » décliné en Démarrage / Croissance /
     * Finition — donne trois cibles pour un même type, alors que la formule ne
     * porte que le type. On en retient une (la plus ancienne active, de manière
     * DÉTERMINISTE) et on signale l'ambiguïté à l'écran plutôt que de trancher
     * en silence : c'est au référentiel d'être précisé, pas au code de deviner.
     */
    public function normCandidates()
    {
        return FoodNorm::query()->active()
            ->where('animal_type', $this->target_type)
            ->orderBy('id')->get();
    }

    /**
     * COUVERTURE ANALYTIQUE d'un nutriment : peut-on comparer honnêtement ?
     *
     * Un ingrédient dont la teneur vaut 0 n'a pas été analysé — aucune matière
     * première réelle n'est à 0 kcal ni à 0 % de lysine. Tant qu'un composant du
     * mélange manque à l'appel, la teneur calculée est SOUS-ESTIMÉE : l'afficher
     * face à la cible dessinait une carence qui n'existe que dans la saisie. La
     * lysine, qu'aucun formulaire ne permettait de renseigner, s'affichait ainsi
     * en rouge à 0 % sur toutes les fiches de l'application.
     */
    public function nutrientCoverage(string $key): array
    {
        $column = FoodNorm::NUTRIENTS[$key]['material'] ?? null;
        $missing = [];
        $contributors = 0;

        foreach ($this->items as $item) {
            if ((float) $item->percentage <= 0) {
                continue;
            }

            $contributors++;

            if ((float) ($item->rawMaterial->{$column} ?? 0) <= 0) {
                $missing[] = $item->rawMaterial->name ?? '—';
            }
        }

        return [
            'complete' => $contributors > 0 && $missing === [],
            'missing'  => $missing,
        ];
    }

    /**
     * Confrontation mélange / norme, nutriment par nutriment.
     *
     * @return array<string, array{label:string, unit:string, decimals:int, real:float,
     *                             target:?float, ratio:?float, complete:bool, missing:array}>
     */
    public function nutritionalComparison(): array
    {
        $profile = $this->nutritional_profile;
        $targets = $this->norm()?->targets() ?? [];
        $rows = [];

        foreach (FoodNorm::NUTRIENTS as $key => $nutrient) {
            $target = $targets[$key] ?? null;
            $coverage = $this->nutrientCoverage($key);

            $rows[$key] = [
                'label'    => $nutrient['label'],
                'unit'     => $nutrient['unit'],
                'decimals' => $nutrient['decimals'],
                'real'     => $profile[$key],
                'target'   => $target > 0 ? $target : null,
                'ratio'    => $target > 0 && $coverage['complete'] ? round($profile[$key] / $target, 4) : null,
                'complete' => $coverage['complete'],
                'missing'  => $coverage['missing'],
            ];
        }

        return $rows;
    }

    /**
     * VERDICT ÉCONOMIQUE — coût de revient face au prix cible du référentiel.
     *
     * La fiche tranchait sur un `coût < 5000` codé en dur et la liste sur un
     * repli à 4500 : un aliment d'alevinage (cible 9 500 GNF/kg) était donc
     * « sous la norme » en vert dans la liste et « À RÉVISER » sur sa propre
     * fiche, à la même seconde. Sans norme rattachée, on ne rend AUCUN verdict :
     * une absence de référence n'est pas une performance.
     *
     * @return array{cost:float, target:?float, diff:?float, status:string, label:string}
     */
    public function economicVerdict(): array
    {
        $cost = $this->cost_per_kg;
        $target = $this->norm()?->targetPrice();

        if (! $target || $target <= 0) {
            return [
                'cost' => $cost, 'target' => null, 'diff' => null,
                'status' => 'unknown', 'label' => __('Aucun prix cible au référentiel'),
            ];
        }

        $diff = round($cost - $target, 2);

        // 5 % de la cible : la marge de bruit des cours des matières premières.
        $tolerance = $target * 0.05;

        if ($diff <= 0) {
            return ['cost' => $cost, 'target' => $target, 'diff' => $diff, 'status' => 'under', 'label' => __('Sous la norme')];
        }

        if ($diff <= $tolerance) {
            return ['cost' => $cost, 'target' => $target, 'diff' => $diff, 'status' => 'near', 'label' => __('Au niveau de la norme')];
        }

        return ['cost' => $cost, 'target' => $target, 'diff' => $diff, 'status' => 'over', 'label' => __('Surcoût')];
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