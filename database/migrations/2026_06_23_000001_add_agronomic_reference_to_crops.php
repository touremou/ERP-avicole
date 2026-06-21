<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RÉFÉRENTIEL AGRONOMIQUE — enrichissement du catalogue cultures (PHASE 1).
 *
 * Ajoute au référentiel `crop_species` des données agronomiques fiables
 * (fenêtres de semis, sols et zones agro-écologiques adaptés, besoin en eau,
 * conseils de rendement) basées sur les normes Guinée / FAO / IRAG.
 *
 * Ajoute aussi `agro_zone` aux parcelles : zone agro-écologique explicite,
 * qui peut être héritée de la région de la ferme à défaut (cf. Plot::zoneFromRegion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crop_species', function (Blueprint $table) {
            $table->json('sowing_months')->nullable()->after('avg_yield_tha'); // mois recommandés [1..12]
            $table->json('soil_types')->nullable()->after('sowing_months');    // sols les mieux adaptés
            $table->json('agro_zones')->nullable()->after('soil_types');       // zones agro-écologiques favorables
            $table->string('water_need')->nullable()->after('agro_zones');     // faible / moyen / eleve
            $table->text('yield_tips')->nullable()->after('water_need');       // conseils d'optimisation du rendement
        });

        Schema::table('plots', function (Blueprint $table) {
            $table->string('agro_zone')->nullable()->after('soil_type');
        });
    }

    public function down(): void
    {
        Schema::table('crop_species', function (Blueprint $table) {
            $table->dropColumn(['sowing_months', 'soil_types', 'agro_zones', 'water_need', 'yield_tips']);
        });

        Schema::table('plots', function (Blueprint $table) {
            $table->dropColumn('agro_zone');
        });
    }
};
