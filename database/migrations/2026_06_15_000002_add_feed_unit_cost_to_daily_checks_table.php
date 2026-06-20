<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot du coût moyen pondéré (CMP) de l'aliment au moment de sa
     * consommation par le lot. Permet de valoriser la consommation au coût
     * de revient réel — qu'il s'agisse d'aliment acheté ou produit à la
     * provenderie — dans la marge du lot, sans dépendre du CMP courant
     * (qui évolue à chaque entrée de stock).
     */
    public function up(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_checks', 'feed_unit_cost')) {
                $table->decimal('feed_unit_cost', 12, 2)
                    ->nullable()
                    ->after('feed_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            if (Schema::hasColumn('daily_checks', 'feed_unit_cost')) {
                $table->dropColumn('feed_unit_cost');
            }
        });
    }
};
