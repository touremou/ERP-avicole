<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LE RÉFÉRENTIEL NORMÉ NE DOIT PAS SE DUPLIQUER.
 *
 * `FoodNormImport` faisait `new FoodNorm([...])` sur chaque ligne du fichier :
 * réimporter le même classeur — le geste normal quand on corrige une cible —
 * ajoutait un jeu complet de normes au lieu de mettre à jour l'existant. Aucune
 * contrainte ne s'y opposait. Les écrans faisaient ensuite
 * `->where('animal_type', ...)->first()` sans ordre : la cible affichée devenait
 * celle que MySQL renvoyait en premier, c'est-à-dire indéterminée, et pouvait
 * changer d'un écran à l'autre.
 *
 * On ramène donc la table à sa clef métier — une cible par (ferme, type, phase)
 * — en conservant la ligne la PLUS RÉCENTE de chaque groupe : c'est la dernière
 * correction saisie, celle que l'utilisateur croyait avoir appliquée.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dedupe();

        Schema::table('food_norms', function (Blueprint $table) {
            $table->unique(['farm_id', 'animal_type', 'phase'], 'food_norms_farm_type_phase_unique');
        });
    }

    public function down(): void
    {
        Schema::table('food_norms', function (Blueprint $table) {
            $table->dropUnique('food_norms_farm_type_phase_unique');
        });
    }

    /** Ne garde que la ligne la plus récente de chaque (farm_id, animal_type, phase). */
    private function dedupe(): void
    {
        $groups = DB::table('food_norms')
            ->select('farm_id', 'animal_type', 'phase', DB::raw('COUNT(*) as total'))
            ->groupBy('farm_id', 'animal_type', 'phase')
            ->having('total', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $keep = DB::table('food_norms')
                ->where('animal_type', $group->animal_type)
                ->where('phase', $group->phase)
                ->when($group->farm_id === null,
                    fn ($q) => $q->whereNull('farm_id'),
                    fn ($q) => $q->where('farm_id', $group->farm_id))
                ->orderByDesc('updated_at')->orderByDesc('id')
                ->value('id');

            DB::table('food_norms')
                ->where('animal_type', $group->animal_type)
                ->where('phase', $group->phase)
                ->when($group->farm_id === null,
                    fn ($q) => $q->whereNull('farm_id'),
                    fn ($q) => $q->where('farm_id', $group->farm_id))
                ->where('id', '!=', $keep)
                ->delete();
        }
    }
};
