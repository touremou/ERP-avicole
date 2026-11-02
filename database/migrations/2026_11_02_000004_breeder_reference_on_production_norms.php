<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RÉFÉRENTIEL REPRODUCTEURS : la matrice hebdomadaire du guide de souche.
 *
 * Décision de l'exploitation (dindons reproducteurs, applicable à toute
 * souche reproductrice) : la semaine du guide porte, en plus du poids, de la
 * ponte HD, de l'aliment et de l'eau déjà connus,
 *   • le poids des MÂLES et des FEMELLES (g) ;
 *   • les œufs incubables (% des œufs pondus) ;
 *   • la fertilité (% des œufs mis à couver) et l'éclosion (% des fertiles),
 *     les définitions d'Incubation::fertility_rate / hatchability_rate ;
 *   • la mortalité + réforme de la semaine (% des sujets présents).
 *
 * Colonnes vides par défaut : les valeurs viennent du guide de la souche,
 * saisies ou importées par la ferme.
 *
 * Réglage semé : l'alerte de ponte sous la courbe, 5 points par défaut
 * (ProductionNorm::ponteSousLaCourbe). Une valeur déjà réglée est respectée.
 */
return new class extends Migration
{
    private const COLONNES = [
        'target_weight_male', 'target_weight_female',
        'target_hatching_egg_rate', 'target_fertility_rate', 'target_hatchability_rate',
        'target_mortality_weekly',
    ];

    public function up(): void
    {
        Schema::table('production_norms', function (Blueprint $table) {
            $table->decimal('target_weight_male', 10, 2)->nullable();
            $table->decimal('target_weight_female', 10, 2)->nullable();
            $table->decimal('target_hatching_egg_rate', 5, 2)->nullable();
            $table->decimal('target_fertility_rate', 5, 2)->nullable();
            $table->decimal('target_hatchability_rate', 5, 2)->nullable();
            $table->decimal('target_mortality_weekly', 5, 2)->nullable();
        });

        if (! DB::table('settings')->where('group', 'elevage')->where('key', 'breeder_laying_gap_points')->exists()) {
            DB::table('settings')->insert([
                'group' => 'elevage', 'key' => 'breeder_laying_gap_points', 'value' => '5',
                'type' => 'number', 'label' => 'Alerte de ponte HD sous la courbe du référentiel reproducteurs',
                'unit' => 'points', 'display_order' => 90,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Setting::clearCache();
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'elevage')->where('key', 'breeder_laying_gap_points')->delete();
        Setting::clearCache();

        Schema::table('production_norms', function (Blueprint $table) {
            $table->dropColumn(self::COLONNES);
        });
    }
};
