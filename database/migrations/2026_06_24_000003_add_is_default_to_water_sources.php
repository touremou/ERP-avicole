<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marque une source d'eau comme « par défaut » de la ferme.
 *
 * Utilisée pour imputer la consommation des lots d'un bâtiment qui n'a pas de
 * source explicitement affectée (cf. Building::resolveWaterSource()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('water_sources', function (Blueprint $table) {
            if (! Schema::hasColumn('water_sources', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('water_sources', function (Blueprint $table) {
            if (Schema::hasColumn('water_sources', 'is_default')) {
                $table->dropColumn('is_default');
            }
        });
    }
};
