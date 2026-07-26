<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T1 — La récolte qui n'est PAS vendue.
 *
 * Problème corrigé : `crop_cycles.total_revenue` sommait le prix unitaire de
 * TOUTES les récoltes, vendues ou non. Une récolte de gombo faite en saison des
 * pluies (prix bas) puis séchée pour être vendue quatre mois plus tard
 * inscrivait donc un revenu JAMAIS encaissé dans le cycle — et la vente réelle,
 * partant du stock, n'y revenait jamais. Le cycle affichait une marge écrasée,
 * la plus-value du séchage n'apparaissait nulle part.
 *
 * Trois colonnes suffisent :
 *  - harvests.destination : vente | transformation | stockage. Seule « vente »
 *    compte au revenu ; le reste sort du cycle EN MATIÈRE (vers le stock), donc
 *    aussi EN COÛT (coût des marchandises vendues, cf. CropCycle::netMargin).
 *  - crop_transformations.harvest_id : quelle récolte a alimenté ce lot séché.
 *    Sans lui, la traçabilité d'un produit vendu quatre mois plus tard est
 *    rompue (même discipline que la BOM inversée de l'abattoir).
 *  - crop_transformations.input_cost / output_unit_cost : le coût de revient du
 *    produit fini. Il était valorisé au PRIX DE VENTE espéré (le formulaire dit
 *    « Prix produit fini »), ce qui gonflait l'inventaire et écrasait à ~0 la
 *    marge du jour de la vente.
 *
 * Rétro-compatible : les récoltes existantes prennent « vente », donc le revenu
 * et la marge de tous les cycles historiques restent inchangés.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('harvests') && ! Schema::hasColumn('harvests', 'destination')) {
            Schema::table('harvests', function (Blueprint $table) {
                $table->string('destination', 20)->default('vente')->after('quality');
                $table->index(['crop_cycle_id', 'destination']);
            });

            // Historique : tout ce qui existait était compté au revenu, donc
            // était (implicitement) vendu. On ne réécrit pas le passé.
            DB::table('harvests')->whereNull('destination')->update(['destination' => 'vente']);
        }

        if (Schema::hasTable('crop_transformations')) {
            Schema::table('crop_transformations', function (Blueprint $table) {
                if (! Schema::hasColumn('crop_transformations', 'harvest_id')) {
                    $table->foreignId('harvest_id')->nullable()->after('crop_cycle_id')
                        ->constrained('harvests')->nullOnDelete();
                }
                if (! Schema::hasColumn('crop_transformations', 'input_cost')) {
                    // Coût de la matière première engagée (kg × coût/kg).
                    $table->decimal('input_cost', 14, 2)->nullable()->after('production_cost');
                }
                if (! Schema::hasColumn('crop_transformations', 'output_unit_cost')) {
                    // Coût de revient par unité de sortie = (input_cost +
                    // production_cost) ÷ output_quantity. C'est LUI qui valorise
                    // le stock du produit fini, jamais le prix de vente visé.
                    $table->decimal('output_unit_cost', 14, 2)->nullable()->after('input_cost');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('harvests', 'destination')) {
            Schema::table('harvests', function (Blueprint $table) {
                $table->dropIndex(['crop_cycle_id', 'destination']);
                $table->dropColumn('destination');
            });
        }

        if (Schema::hasTable('crop_transformations')) {
            Schema::table('crop_transformations', function (Blueprint $table) {
                if (Schema::hasColumn('crop_transformations', 'harvest_id')) {
                    $table->dropConstrainedForeignId('harvest_id');
                }
                foreach (['input_cost', 'output_unit_cost'] as $column) {
                    if (Schema::hasColumn('crop_transformations', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
