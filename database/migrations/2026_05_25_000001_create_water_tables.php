<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('water_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                              // "Forage principal", "Citerne bâtiment A"
            $table->enum('type', ['seeg', 'forage', 'citerne', 'camion']);
            $table->decimal('capacity_liters', 12, 2)->nullable();              // Capacité max (citernes)
            $table->decimal('current_level_liters', 12, 2)->nullable();         // Niveau actuel
            $table->decimal('current_level_percent', 5, 2)->nullable();
            $table->enum('quality_status', ['bon', 'acceptable', 'traitement_requis'])->default('bon');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('water_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('water_source_id')->constrained()->cascadeOnDelete();
            $table->date('reading_date');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('volume_consumed_liters', 12, 2)->default(0);       // Conso du jour
            $table->decimal('volume_added_liters', 12, 2)->default(0);          // Remplissage citerne
            $table->decimal('quality_ph', 4, 2)->nullable();                    // pH (6.5-8.5 idéal volaille)
            $table->decimal('chlorine_level', 4, 2)->nullable();                // Chlore résiduel (mg/L)
            $table->decimal('cost', 12, 2)->default(0);                         // Coût (facture SEEG, pompage)
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['water_source_id', 'reading_date'], 'water_reading_unique_per_day');
            $table->index('reading_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('water_readings');
        Schema::dropIfExists('water_sources');
    }
};
