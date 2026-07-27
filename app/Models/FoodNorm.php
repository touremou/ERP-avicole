<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * NORME NUTRITIONNELLE — la cible d'une phase d'alimentation.
 *
 * Cette table est LA source des cibles (énergie, protéine, acides aminés,
 * minéraux, prix). Avant ce lot, chaque écran de la provenderie portait sa
 * propre copie de repli — 3000 kcal ici, 21 % de protéine là, 4500 GNF/kg dans
 * la liste et 5000 dans la fiche — si bien qu'une même formule pouvait être
 * déclarée « sous la norme » sur un écran et « à réviser » sur l'autre.
 *
 * Désormais : soit une norme est rattachée et elle seule dit la cible, soit il
 * n'y en a pas et l'application le DIT au lieu d'inventer un chiffre.
 */
class FoodNorm extends Model
{
    use HasFactory, BelongsToFarm;

    /**
     * TABLE DES NUTRIENTS — définition unique, partagée par le référentiel des
     * normes, le catalogue des matières premières et tous les écrans.
     *
     * `material` désigne la colonne de `raw_materials` qui porte la teneur de
     * l'ingrédient ; `target` celle de `food_norms` qui porte la cible. Les deux
     * doivent exister, sinon la cible est un vœu que rien ne peut confronter :
     * c'était le cas de la méthionine et du phosphore, ciblés par le référentiel
     * mais absents du catalogue.
     */
    public const NUTRIENTS = [
        'em' => [
            'label' => 'Énergie (EM)', 'unit' => 'kcal/kg', 'decimals' => 0,
            'material' => 'energy_kcal', 'target' => 'target_em',
        ],
        'pb' => [
            'label' => 'Protéine brute', 'unit' => '%', 'decimals' => 1,
            'material' => 'protein_rate', 'target' => 'target_pb',
        ],
        'lys' => [
            'label' => 'Lysine', 'unit' => '%', 'decimals' => 2,
            'material' => 'lysine_rate', 'target' => 'target_lys',
        ],
        'meth' => [
            'label' => 'Méthionine', 'unit' => '%', 'decimals' => 2,
            'material' => 'methionine_rate', 'target' => 'target_meth',
        ],
        'ca' => [
            'label' => 'Calcium', 'unit' => '%', 'decimals' => 2,
            'material' => 'calcium_rate', 'target' => 'target_ca',
        ],
        'p' => [
            'label' => 'Phosphore', 'unit' => '%', 'decimals' => 2,
            'material' => 'phosphorus_rate', 'target' => 'target_p',
        ],
    ];

    /** Colonnes du fichier d'import, dans l'ordre attendu (cf. FoodNormImport). */
    public const IMPORT_COLUMNS = [
        'name', 'animal_type', 'phase',
        'target_em', 'target_pb', 'target_lys', 'target_meth', 'target_ca', 'target_p',
        'target_price_kg',
    ];

    // Indispensable pour l'importation
    protected $fillable = [
        'farm_id',
        'name',
        'animal_type',
        'phase',
        'target_em',
        'target_pb',
        'target_lys',
        'target_meth',
        'target_ca',
        'target_p',
        'target_price_kg',
        'is_active',
    ];

    protected $casts = [
        'target_em'       => 'decimal:2',
        'target_pb'       => 'decimal:2',
        'target_lys'      => 'decimal:2',
        'target_meth'     => 'decimal:2',
        'target_ca'       => 'decimal:2',
        'target_p'        => 'decimal:2',
        'target_price_kg' => 'decimal:2',
        'is_active'       => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Norme applicable à une formule.
     *
     * Le rattachement se fait par `formulas.target_type` = `food_norms.animal_type`,
     * qui est ce que les formulaires enregistrent depuis toujours. La table est
     * cependant clefée sur (animal_type, phase) : un fichier d'import renseigné
     * de la manière naturelle — « chair » décliné en Démarrage / Croissance /
     * Finition — produit trois lignes pour un même type. On préfère alors la
     * plus ancienne ligne ACTIVE, de manière DÉTERMINISTE, afin que deux écrans
     * affichés à la même seconde ne tombent pas sur deux normes différentes
     * (`first()` sans ordre ne garantissait rien) — et l'ambiguïté est signalée
     * à l'écran, cf. Formula::normCandidates().
     */
    public static function resolveFor(?string $animalType): ?self
    {
        if (blank($animalType)) {
            return null;
        }

        return static::query()->canonical()->where('animal_type', $animalType)->first();
    }

    /**
     * Ordre canonique du référentiel : actif d'abord, du plus ancien au plus
     * récent. Déclaré ici pour que la résolution unitaire et la résolution en
     * masse ne puissent pas désigner deux normes différentes.
     */
    public function scopeCanonical($query)
    {
        return $query->active()->orderBy('id');
    }

    /**
     * Référentiel actif indexé par type d'animal — une seule requête.
     *
     * La liste des formules résolvait sa norme formule par formule ; sur une
     * table de quarante lignes cela faisait autant de requêtes que de recettes.
     */
    public static function indexByAnimalType(): \Illuminate\Support\Collection
    {
        return static::query()->canonical()->get()
            ->unique('animal_type')   // la plus ancienne l'emporte, comme resolveFor()
            ->keyBy('animal_type');
    }

    /** Cibles nutritionnelles indexées par clef de nutriment. */
    public function targets(): array
    {
        $targets = [];

        foreach (self::NUTRIENTS as $key => $nutrient) {
            $value = $this->{$nutrient['target']};
            $targets[$key] = $value === null ? null : (float) $value;
        }

        return $targets;
    }

    /** Prix cible au kg, ou null si le référentiel ne le renseigne pas. */
    public function targetPrice(): ?float
    {
        return $this->target_price_kg === null ? null : (float) $this->target_price_kg;
    }
}
