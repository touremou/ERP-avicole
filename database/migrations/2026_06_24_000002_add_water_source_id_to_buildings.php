<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affecte une source d'eau (citerne / forage / SEEG) à un bâtiment.
 *
 * Permet d'imputer automatiquement la consommation d'eau des lots du bâtiment
 * (saisie au pointage journalier) à la bonne citerne — cf. App\Actions\
 * DailyCheck\SyncWaterConsumption. Nullable : un bâtiment sans source affectée
 * retombe sur la source « par défaut » de la ferme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            if (! Schema::hasColumn('buildings', 'water_source_id')) {
                $table->foreignId('water_source_id')
                    ->nullable()
                    ->after('status')
                    ->constrained('water_sources')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            if (Schema::hasColumn('buildings', 'water_source_id')) {
                $table->dropConstrainedForeignId('water_source_id');
            }
        });
    }
};
