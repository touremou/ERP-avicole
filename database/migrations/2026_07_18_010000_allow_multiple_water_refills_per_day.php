<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les ravitaillements (appoints) sont des ÉVÉNEMENTS : plusieurs par jour sont
 * possibles, et ils coexistent avec le relevé de consommation du jour. La
 * contrainte unique (water_source_id, reading_date) — pensée pour « un relevé
 * par jour » — les faisait échouer (Duplicate entry → 500 au push). On la lève
 * et on distingue les appoints via `is_refill`. Le relevé garde son unicité via
 * updateOrCreate sur (source, date, is_refill=false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('water_readings', function (Blueprint $table) {
            if (! Schema::hasColumn('water_readings', 'is_refill')) {
                $table->boolean('is_refill')->default(false)->after('volume_added_liters');
            }
        });

        // Drop de l'unique « un relevé par jour » (nom explicite à la création).
        try {
            Schema::table('water_readings', function (Blueprint $table) {
                $table->dropUnique('water_reading_unique_per_day');
            });
        } catch (\Throwable $e) {
            // Déjà absente (environnement recréé) : sans effet.
        }
    }

    public function down(): void
    {
        Schema::table('water_readings', function (Blueprint $table) {
            if (Schema::hasColumn('water_readings', 'is_refill')) {
                $table->dropColumn('is_refill');
            }
        });
    }
};
