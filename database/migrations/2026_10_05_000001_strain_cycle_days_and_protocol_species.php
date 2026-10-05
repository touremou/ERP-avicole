<?php

use App\Models\ProductionNorm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEUX RÉFÉRENTIELS QUI NE SAVAIENT PAS CE QUE L'ÉCRAN LEUR PRÊTAIT.
 *
 * 1. DURÉE DE CYCLE PAR SOUCHE. La fin d'une bande se calculait sur le seul
 *    type de production (Poulet de chair : 45 j). Changer de souche ne changeait
 *    rien, alors qu'un poulet local Cou Nu se mène en ~16 semaines et une dinde
 *    BUT 6 en ~20. `cycle_days` porte la durée PROPRE à une souche ; vide, le
 *    type de production fait foi comme avant. Valeur répétée sur chaque ligne
 *    hebdomadaire de la souche (une norme = une semaine) : c'est la souche, pas
 *    la semaine, qui la porte — `ProductionNorm::cycleDaysFor()` la lit.
 *
 * 2. ESPÈCE DU PROTOCOLE. Les protocoles n'avaient qu'un TYPE (chair, ponte…),
 *    commun à toutes les espèces : « Prophylaxie Dinde » (type chair) s'offrait
 *    pour un poulet de chair. `species_id` vide = protocole générique.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('production_norms', 'cycle_days')) {
            Schema::table('production_norms', function (Blueprint $table) {
                $table->unsignedSmallInteger('cycle_days')->nullable()->after('model_name');
            });
        }

        // Durées livrées : UNE déclaration, celle du semeur.
        foreach (\Database\Seeders\ProductionNormSeeder::CYCLES as $model => $days) {
            DB::table('production_norms')->where('model_name', $model)->whereNull('cycle_days')
                ->update(['cycle_days' => $days]);
        }

        if (! Schema::hasColumn('protocols', 'species_id')) {
            Schema::table('protocols', function (Blueprint $table) {
                $table->foreignId('species_id')->nullable()->after('type')
                    ->constrained('species')->nullOnDelete();
            });
        }

        // Rattachement des protocoles existants : même devineur que les souches,
        // sur le nom ET la souche déclarée. Ce qu'il ne reconnaît pas reste
        // générique — rien ne disparaît d'un sélecteur.
        $species = DB::table('species')->pluck('id', 'slug');

        DB::table('protocols')->whereNull('species_id')->get(['id', 'name', 'strain'])
            ->each(function ($p) use ($species) {
                $slug = ProductionNorm::guessSpeciesSlug(trim($p->name . ' ' . $p->strain));

                if ($slug && isset($species[$slug])) {
                    DB::table('protocols')->where('id', $p->id)->update(['species_id' => $species[$slug]]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('protocols', 'species_id')) {
            Schema::table('protocols', function (Blueprint $table) {
                $table->dropConstrainedForeignId('species_id');
            });
        }

        if (Schema::hasColumn('production_norms', 'cycle_days')) {
            Schema::table('production_norms', function (Blueprint $table) {
                $table->dropColumn('cycle_days');
            });
        }
    }
};
