<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frais d'incubation du cycle (overhead) — absorption complète (« version usine »).
 *
 * S'ajoute au coût des œufs dans le coût de revient du poussin :
 *   coût/poussin = (eggs_count × egg_unit_cost + overhead_cost) ÷ poussins éclos.
 *
 * L'overhead regroupe l'énergie (groupe/EDG), la main-d'œuvre et l'amortissement
 * de la couveuse, alloués au cycle. Saisi à l'incubation ou dérivé d'un taux
 * paramétrable par œuf (couvoir.overhead_per_egg).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incubations', function (Blueprint $table) {
            if (! Schema::hasColumn('incubations', 'overhead_cost')) {
                $table->decimal('overhead_cost', 12, 2)->default(0)->after('egg_unit_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('incubations', function (Blueprint $table) {
            if (Schema::hasColumn('incubations', 'overhead_cost')) {
                $table->dropColumn('overhead_cost');
            }
        });
    }
};
