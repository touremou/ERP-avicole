<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incubations', function (Blueprint $table) {
            // 1. Ajout des nouveaux champs industriels
            $table->integer('incubation_duration')->default(21)->after('start_date');
            $table->dateTime('finished_at')->nullable()->after('status');

            // 2. Correction sémantique (0 -> NULL) pour les étapes futures
            // Nécessite que doctrine/dbal soit installé si tu es sur une version < Laravel 10
            $table->integer('fertile_eggs')->nullable()->default(null)->change();
            $table->integer('hatched_chicks')->nullable()->default(null)->change();
            $table->decimal('fertility_rate', 5, 2)->nullable()->default(null)->change();
            $table->decimal('hatchability_rate', 5, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('incubations', function (Blueprint $table) {
            // Rollback en cas de besoin
            $table->dropColumn(['incubation_duration', 'finished_at']);
            
            $table->integer('fertile_eggs')->nullable(false)->default(0)->change();
            $table->integer('hatched_chicks')->nullable(false)->default(0)->change();
            $table->decimal('fertility_rate', 5, 2)->nullable(false)->default(0.00)->change();
            $table->decimal('hatchability_rate', 5, 2)->nullable(false)->default(0.00)->change();
        });
    }
};