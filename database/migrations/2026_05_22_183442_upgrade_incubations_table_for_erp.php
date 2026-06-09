<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incubations', function (Blueprint $table) {
            // 1. Ajout des nouveaux champs industriels (idempotent)
            if (!Schema::hasColumn('incubations', 'incubation_duration')) {
                $table->integer('incubation_duration')->default(21)->after('start_date');
            }
            if (!Schema::hasColumn('incubations', 'finished_at')) {
                $table->dateTime('finished_at')->nullable()->after('status');
            }
        });

        // 2. Correction sémantique (0 -> NULL) — ->change() not supported on SQLite, wrap in try/catch
        try {
            Schema::table('incubations', function (Blueprint $table) {
                $table->integer('fertile_eggs')->nullable()->default(null)->change();
                $table->integer('hatched_chicks')->nullable()->default(null)->change();
                $table->decimal('fertility_rate', 5, 2)->nullable()->default(null)->change();
                $table->decimal('hatchability_rate', 5, 2)->nullable()->default(null)->change();
            });
        } catch (\Throwable $e) {
            // SQLite does not support column modification — skip silently
        }
    }

    public function down(): void
    {
        Schema::table('incubations', function (Blueprint $table) {
            if (Schema::hasColumn('incubations', 'incubation_duration')) {
                $table->dropColumn('incubation_duration');
            }
            if (Schema::hasColumn('incubations', 'finished_at')) {
                $table->dropColumn('finished_at');
            }
        });

        try {
            Schema::table('incubations', function (Blueprint $table) {
                $table->integer('fertile_eggs')->nullable(false)->default(0)->change();
                $table->integer('hatched_chicks')->nullable(false)->default(0)->change();
                $table->decimal('fertility_rate', 5, 2)->nullable(false)->default(0.00)->change();
                $table->decimal('hatchability_rate', 5, 2)->nullable(false)->default(0.00)->change();
            });
        } catch (\Throwable $e) {
            // SQLite does not support column modification — skip silently
        }
    }
};
