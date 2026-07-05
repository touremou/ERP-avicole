<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pesées individuelles de l'échantillon hebdomadaire (kg, JSON).
 *
 * L'uniformité n'est plus saisie « toute faite » : l'opérateur saisit les
 * poids un à un, l'ERP calcule poids moyen ET taux d'uniformité (part des
 * sujets à ±10 % de la moyenne) — côté serveur, source de vérité. Les pesées
 * sont conservées : l'uniformité affichée est VÉRIFIABLE (recalculable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            $table->json('weight_samples')->nullable()->after('uniformity_pct');
        });
    }

    public function down(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            $table->dropColumn('weight_samples');
        });
    }
};
