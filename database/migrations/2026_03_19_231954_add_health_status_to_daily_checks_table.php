<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            // Ajout sécurisé des colonnes techniques si elles manquent
            if (!Schema::hasColumn('daily_checks', 'temp_min')) {
                $table->decimal('temp_min', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('daily_checks', 'temp_max')) {
                $table->decimal('temp_max', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('daily_checks', 'humidity')) {
                $table->decimal('humidity', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('daily_checks', 'water_consumed')) {
                $table->decimal('water_consumed', 8, 2)->default(0);
            }
            if (!Schema::hasColumn('daily_checks', 'qty_quarantine_in')) {
                $table->integer('qty_quarantine_in')->default(0);
            }
            if (!Schema::hasColumn('daily_checks', 'qty_quarantine_out')) {
                $table->integer('qty_quarantine_out')->default(0);
            }
            if (!Schema::hasColumn('daily_checks', 'qty_sorted_out')) {
                $table->integer('qty_sorted_out')->default(0);
            }
            if (!Schema::hasColumn('daily_checks', 'health_status')) {
                $table->string('health_status')->default('Normal');
            }
            if (!Schema::hasColumn('daily_checks', 'avg_weight')) {
                $table->decimal('avg_weight', 8, 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            $table->dropColumn([
                'temp_min', 'temp_max', 'humidity', 'water_consumed', 
                'qty_quarantine_in', 'qty_quarantine_out', 'qty_sorted_out', 
                'health_status', 'avg_weight'
            ]);
        });
    }
};