<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indicateurs de bien-être animal observés au pointage (sujets vivants
     * mais en souffrance) : boiterie et picage/cannibalisme. Comptages bruts,
     * NON déduits de l'effectif (un sujet boiteux reste vivant) — ils
     * alimentent des taux et des alertes préventives de biosécurité.
     */
    public function up(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_checks', 'lame_count')) {
                $table->unsignedInteger('lame_count')->nullable()->after('health_status');
            }
            if (! Schema::hasColumn('daily_checks', 'pecking_injury_count')) {
                $table->unsignedInteger('pecking_injury_count')->nullable()->after('lame_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            foreach (['lame_count', 'pecking_injury_count'] as $col) {
                if (Schema::hasColumn('daily_checks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
