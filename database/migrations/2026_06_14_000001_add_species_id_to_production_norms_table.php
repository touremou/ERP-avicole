<?php

use App\Models\ProductionNorm;
use App\Models\Species;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache les souches/normes de production à une espèce.
 *
 * Jusqu'ici les `production_norms` (souches : Ross 308, ISA Brown, Caille
 * Japonaise, Tilapia du Nil...) n'étaient discriminées que par `batch_type`
 * (chair, ponte, ...). Or plusieurs espèces partagent un même type (ex.
 * 'ponte' = poule pondeuse ET caille), ce qui faisait apparaître des souches
 * d'autres espèces dans le sélecteur de souche d'un lot (ex. « Caille
 * Japonaise » proposée pour un lot de poulets pondeuses).
 *
 * `species_id` est nullable : une norme sans espèce reste « générique » et
 * s'affiche pour toutes les espèces (compatibilité ascendante).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('production_norms', 'species_id')) {
            Schema::table('production_norms', function (Blueprint $table) {
                $table->foreignId('species_id')->nullable()->after('id')
                    ->constrained('species')->nullOndelete();
            });
        }

        // Backfill : déduire l'espèce de chaque souche existante par mots-clés.
        $speciesBySlug = Species::pluck('id', 'slug');

        foreach (ProductionNorm::whereNull('species_id')->get() as $norm) {
            $slug = ProductionNorm::guessSpeciesSlug($norm->model_name);
            if ($slug && isset($speciesBySlug[$slug])) {
                DB::table('production_norms')
                    ->where('id', $norm->id)
                    ->update(['species_id' => $speciesBySlug[$slug]]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('production_norms', 'species_id')) {
            Schema::table('production_norms', function (Blueprint $table) {
                $table->dropConstrainedForeignId('species_id');
            });
        }
    }
};
