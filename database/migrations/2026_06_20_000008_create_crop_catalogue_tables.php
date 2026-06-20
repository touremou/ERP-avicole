<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATALOGUE DES CULTURES (module: cultures).
 *
 * Référentiel partagé des espèces cultivées et de leurs variétés, adapté au
 * contexte guinéen (nom local, famille, durée de cycle, rendement de référence).
 * Sert de base de connaissances pour pré-remplir un cycle de culture et pour
 * benchmarker le rendement réel d'un cycle contre le rendement attendu.
 *
 * Volontairement NON multi-ferme : c'est un référentiel agronomique partagé,
 * pas une donnée d'exploitation. On garde tout de même uuid + sync offline.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── ESPÈCES / CULTURES ───
        Schema::create('crop_species', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->string('type')->default('autre');        // cf. CropSpecies::TYPES
            $table->string('name');                           // maïs, manioc, tomate…
            $table->string('local_name')->nullable();         // nom vernaculaire
            $table->string('family')->nullable();             // famille botanique
            $table->unsignedSmallInteger('cycle_days_min')->nullable();
            $table->unsignedSmallInteger('cycle_days_max')->nullable();
            $table->decimal('avg_yield_tha', 8, 2)->nullable(); // rendement moyen t/ha
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
        });

        // ─── VARIÉTÉS ───
        Schema::create('crop_varieties', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->foreignId('crop_species_id')->constrained('crop_species')->cascadeOnDelete();

            $table->string('name');
            $table->unsignedSmallInteger('cycle_days')->nullable();
            $table->decimal('avg_yield_tha', 8, 2)->nullable();
            $table->string('cycle_type')->nullable();         // précoce, tardive, hâtive…
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('crop_species_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_varieties');
        Schema::dropIfExists('crop_species');
    }
};
