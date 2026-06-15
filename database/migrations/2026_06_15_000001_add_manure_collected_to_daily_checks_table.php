<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la quantité de fumier ramassé lors d'un renouvellement de litière.
 *
 * Le ramassage de litière (litter_changed) était jusqu'ici un simple booléen
 * sans valorisation. Or les copeaux de bois étalés comme litière, mélangés
 * aux déjections, constituent un fumier vendu comme fertilisant : une recette
 * à tracer. Cette colonne capte le poids collecté (KG), qui alimente l'article
 * de stock « Fumier » (cf. App\Actions\DailyCheck\SyncManureCollection).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_checks', 'manure_collected_kg')) {
                $table->decimal('manure_collected_kg', 10, 2)
                    ->nullable()
                    ->after('litter_changed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            if (Schema::hasColumn('daily_checks', 'manure_collected_kg')) {
                $table->dropColumn('manure_collected_kg');
            }
        });
    }
};
