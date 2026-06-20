<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RELEVÉS MÉTÉO & PLUVIOMÉTRIE (module: cultures).
 *
 * Suivi climatique par ferme (et optionnellement par parcelle) : température,
 * humidité, pluviométrie, vent, ensoleillement. Données clés en agriculture
 * pluviale guinéenne pour corréler rendements et conditions climatiques.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_readings', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plot_id')->nullable()->constrained()->nullOnDelete();

            $table->date('reading_date');
            $table->decimal('temperature_min', 5, 1)->nullable(); // °C
            $table->decimal('temperature_max', 5, 1)->nullable(); // °C
            $table->decimal('humidity_pct', 5, 1)->nullable();    // %
            $table->decimal('rainfall_mm', 8, 1)->default(0);     // mm
            $table->decimal('wind_kmh', 6, 1)->nullable();        // km/h
            $table->decimal('sunshine_h', 4, 1)->nullable();      // heures
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'reading_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_readings');
    }
};
