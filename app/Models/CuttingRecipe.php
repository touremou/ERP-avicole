<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recette de désassemblage (BOM inversée) : un article brut (carcasse) →
 * co-produits / sous-produits / déchets, avec rendements attendus et
 * coefficients de valeur (répartition des coûts conjoints, Lot 3).
 * Une seule recette active par ferme + famille d'espèce.
 */
class CuttingRecipe extends Model
{
    use BelongsToFarm;

    protected $fillable = ['farm_id', 'species_family', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function lines(): HasMany
    {
        return $this->hasMany(CuttingRecipeLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Recette active pour une famille d'espèce (null → repli nomenclature). */
    public static function activeFor(?string $family): ?self
    {
        return static::where('species_family', $family ?? config('butchery.default_family'))
            ->where('is_active', true)
            ->with('lines')
            ->first();
    }

    /**
     * Matérialise une recette depuis la nomenclature config/butchery.php
     * (point de départ éditable — jamais écrasée ensuite par la config).
     */
    public static function seedFromNomenclature(string $family, ?int $farmId = null): self
    {
        $recipe = static::create([
            'farm_id'        => $farmId ?? session('current_farm_id'),
            'species_family' => $family,
            'name'           => 'Recette ' . $family,
            'is_active'      => true,
        ]);

        $cuts = config("butchery.cuts.{$family}", []);
        foreach ($cuts as $i => $cut) {
            $recipe->lines()->create([
                'cut_code'            => $cut['code'],
                'label'               => $cut['label'],
                // La nomenclature ne distingue pas la nature : les abats sont
                // des sous-produits, le reste des co-produits (affinable ensuite).
                'output_type'         => in_array($cut['code'], ['abats', 'foie', 'gesier'], true)
                    ? CuttingRecipeLine::TYPE_SOUS_PRODUIT
                    : CuttingRecipeLine::TYPE_CO_PRODUIT,
                'default_destination' => $cut['destination'] ?? 'stock_frais',
                'is_default'          => (bool) ($cut['default'] ?? false),
                'sort_order'          => $i,
            ]);
        }

        // Ligne déchet systématique : la balance de masse exige de peser ce qui
        // part au rebut (os, parures) — pas seulement de le déduire.
        $recipe->lines()->create([
            'cut_code'            => 'dechet',
            'label'               => 'Déchets (os, parures)',
            'output_type'         => CuttingRecipeLine::TYPE_DECHET,
            'default_destination' => 'vente_directe',
            'is_default'          => false,
            'sort_order'          => count($cuts) + 1,
        ]);

        return $recipe->load('lines');
    }
}
