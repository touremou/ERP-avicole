<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UUID d'idempotence sur les relevés/appoints d'eau : permet à la PWA de
 * (re)pousser un ravitaillement hors-ligne (water_refill.create) sans doublon.
 * Nullable — les lignes web existantes n'en ont pas besoin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('water_readings', function (Blueprint $table) {
            if (! Schema::hasColumn('water_readings', 'uuid')) {
                $table->uuid('uuid')->nullable()->after('id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('water_readings', function (Blueprint $table) {
            if (Schema::hasColumn('water_readings', 'uuid')) {
                $table->dropColumn('uuid');
            }
        });
    }
};
