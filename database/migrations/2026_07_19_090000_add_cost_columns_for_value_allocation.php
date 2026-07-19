<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 3 (refonte désassemblage) — répartition des coûts conjoints par VALEUR :
 *  - cut_products.unit_cost : coût de revient /kg attribué à chaque ligne de
 *    découpe (1 kg de filet ne porte pas le coût d'1 kg de pattes) ;
 *  - transformations.source_unit_cost : coût /kg de la matière engagée, FIGÉ à
 *    la création — indispensable pour les transformations routées depuis la
 *    découpe (la matière n'est jamais passée par le stock produits finis).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cut_products', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
        });

        Schema::table('transformations', function (Blueprint $table) {
            $table->decimal('source_unit_cost', 12, 2)->nullable()->after('production_cost');
        });
    }

    public function down(): void
    {
        Schema::table('cut_products', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });

        Schema::table('transformations', function (Blueprint $table) {
            $table->dropColumn('source_unit_cost');
        });
    }
};
