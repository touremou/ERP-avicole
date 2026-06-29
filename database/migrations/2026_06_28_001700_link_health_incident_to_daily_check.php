<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relie un incident sanitaire au POINTAGE journalier qui l'a révélé
 * (daily_check_id), pour la traçabilité « pointage → incident ». La mortalité
 * reste comptée UNE SEULE FOIS, dans le pointage (l'incident est qualitatif) :
 * aucun double comptage. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('health_incidents') || Schema::hasColumn('health_incidents', 'daily_check_id')) {
            return;
        }

        Schema::table('health_incidents', function (Blueprint $table) {
            $table->foreignId('daily_check_id')->nullable()->after('batch_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Conservateur : on conserve la colonne et les liens existants.
    }
};
