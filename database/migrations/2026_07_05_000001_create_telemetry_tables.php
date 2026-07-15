<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingestion IoT DÉCOUPLÉE (exigences pré-MEP 2 & 3) — matériel non défini,
 * architecture future-proof :
 *
 * - telemetry_sensors : registre capteur → bâtiment (le contrat d'ingestion
 *   ne transporte QUE sensor_id/timestamp/value/unit ; le lieu vient d'ici).
 *
 * - telemetry_logs : ZONE TAMPON. L'endpoint API écrit ici et UNIQUEMENT ici
 *   (aucun verrou sur les tables métier) ; le worker telemetry:process associe
 *   ensuite chaque relevé au lot actif du bâtiment (heure + lieu).
 *
 * - daily_checks.temp_source / temp_recorded_by : traçabilité de l'ORIGINE de
 *   la température du pointage (capteur IoT ou saisie manuelle + opérateur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_sensors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->index();
            $table->string('sensor_id', 64)->unique();   // identifiant matériel
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telemetry_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->index();
            $table->string('sensor_id', 64);
            $table->string('metric', 30)->default('temperature');
            $table->decimal('value', 6, 2);
            $table->string('unit', 15)->default('celsius');
            $table->timestamp('recorded_at')->nullable(); // heure EXACTE du relevé (ISO 8601) ; nullable = pas de ON UPDATE implicite (le status évolue)
            $table->foreignId('building_id')->nullable();
            $table->foreignId('batch_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending | linked | orphan
            $table->timestamp('created_at')->nullable();

            $table->index(['sensor_id', 'recorded_at']);
            $table->index(['status', 'recorded_at']);
            $table->index(['building_id', 'recorded_at']);
        });

        Schema::table('daily_checks', function (Blueprint $table) {
            $table->string('temp_source', 10)->nullable()->after('temp_max');      // manuel | iot
            $table->string('temp_recorded_by')->nullable()->after('temp_source');  // opérateur ou capteur
        });
    }

    public function down(): void
    {
        Schema::table('daily_checks', function (Blueprint $table) {
            $table->dropColumn(['temp_source', 'temp_recorded_by']);
        });
        Schema::dropIfExists('telemetry_logs');
        Schema::dropIfExists('telemetry_sensors');
    }
};
