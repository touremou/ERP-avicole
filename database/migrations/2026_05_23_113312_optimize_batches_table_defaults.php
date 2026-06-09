<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ->change() calls are not supported on SQLite — wrap in try/catch for idempotency
        try {
            Schema::table('batches', function (Blueprint $table) {
                // 1. Les quantités : Par défaut à 0 (Règle d'or en ERP)
                $table->integer('qty_alive')->default(0)->change();
                $table->integer('qty_dead')->default(0)->change();
                $table->integer('qty_males')->default(0)->change();
                $table->integer('qty_females')->default(0)->change();

                // 2. Champs financiers et attributs génériques
                $table->decimal('buy_price_per_unit', 10, 2)->default(0)->change();
                $table->string('model_name')->default('Non spécifié')->nullable()->change();

                // 3. Dates : Un lot généré automatiquement peut ne pas avoir de date d'arrivée physique
                $table->date('arrival_date')->nullable()->change();

                // 4. Booléens
                $table->boolean('vaccination_received')->default(false)->change();
            });
        } catch (\Throwable $e) {
            // SQLite does not support column modification — skip silently
        }
    }

    public function down(): void
    {
        try {
            Schema::table('batches', function (Blueprint $table) {
                // Rollback (retrait des valeurs par défaut)
                $table->integer('qty_alive')->default(null)->change();
                $table->integer('qty_dead')->default(null)->change();
                $table->integer('qty_males')->default(null)->change();
                $table->integer('qty_females')->default(null)->change();

                $table->decimal('buy_price_per_unit', 10, 2)->default(null)->change();
                $table->string('model_name')->default(null)->nullable(false)->change();
                $table->date('arrival_date')->nullable(false)->change();
                $table->boolean('vaccination_received')->default(null)->change();
            });
        } catch (\Throwable $e) {
            // SQLite does not support column modification — skip silently
        }
    }
};
