<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrichissement du référentiel zootechnique (fiches officielles de souche —
 * 1re application : guide d'élevage ISA Brown / Hendrix Genetics) :
 *
 * - production_norms : fourchettes conso/poids (min-max au lieu d'une seule
 *   cible), uniformité cible du lot, programme lumineux (heures + lux) et
 *   températures de bâtiment par semaine. Colonnes nullables : les souches
 *   sans fiche détaillée restent sur les cibles simples existantes.
 *
 * - daily_checks : taux d'uniformité mesuré à la pesée (part des sujets dans
 *   ±10 % du poids moyen) — l'indicateur d'homogénéité que suivent les guides
 *   de souche (objectif ≥ 80 %).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_norms', function (Blueprint $table) {
            $table->decimal('feed_min_daily', 8, 2)->nullable()->after('target_feed_daily');  // g/sujet/j
            $table->decimal('feed_max_daily', 8, 2)->nullable()->after('feed_min_daily');
            $table->decimal('weight_min', 8, 2)->nullable()->after('target_weight');          // g
            $table->decimal('weight_max', 8, 2)->nullable()->after('weight_min');
            $table->decimal('uniformity_target', 5, 2)->nullable()->after('target_laying_rate'); // %
            $table->decimal('light_hours', 4, 1)->nullable()->after('uniformity_target');     // h/j
            $table->unsignedSmallInteger('light_lux_min')->nullable()->after('light_hours');
            $table->unsignedSmallInteger('light_lux_max')->nullable()->after('light_lux_min');
            $table->decimal('temp_min_c', 4, 1)->nullable()->after('light_lux_max');          // °C bâtiment
            $table->decimal('temp_max_c', 4, 1)->nullable()->after('temp_min_c');
        });

        Schema::table('daily_checks', function (Blueprint $table) {
            $table->decimal('uniformity_pct', 5, 2)->nullable()->after('avg_weight'); // % sujets à ±10 %
        });
    }

    public function down(): void
    {
        Schema::table('production_norms', function (Blueprint $table) {
            $table->dropColumn([
                'feed_min_daily', 'feed_max_daily', 'weight_min', 'weight_max',
                'uniformity_target', 'light_hours', 'light_lux_min', 'light_lux_max',
                'temp_min_c', 'temp_max_c',
            ]);
        });

        Schema::table('daily_checks', function (Blueprint $table) {
            $table->dropColumn('uniformity_pct');
        });
    }
};
