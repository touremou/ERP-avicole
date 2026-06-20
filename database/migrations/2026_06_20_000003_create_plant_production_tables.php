<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domaine PRODUCTION VÉGÉTALE (module: cultures).
 *
 * Modèles NATIFS — volontairement distincts du triptyque animal
 * Species/Batch/Building, dont le vocabulaire (mortalité, effectif vivant,
 * habitat) ne convient pas aux cultures. On mirroir cependant les patterns
 * éprouvés de Batch : uuid + sync offline, farm_id (BelongsToFarm), statuts
 * en constantes, soft deletes.
 *
 *   parcelle (plot) ── 1,n ──> cycle de culture (crop_cycle) ── 1,n ──> récolte (harvest)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── PARCELLES ───
        Schema::create('plots', function (Blueprint $table) {
            $table->id();

            // Sync offline (cohérent avec HasStandardUuid / HasOfflineSync)
            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code')->nullable();
            $table->string('name');
            $table->decimal('area_ha', 10, 4)->default(0);      // superficie totale (hectares)
            $table->string('location')->nullable();             // localisation / GPS
            $table->string('soil_type')->nullable();            // type de sol
            $table->string('irrigation_type')->nullable();      // pluvial, goutte-à-goutte, aspersion…
            $table->string('status')->default('disponible');    // cf. Plot::STATUS_*
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'status']);
        });

        // ─── CYCLES DE CULTURE ───
        Schema::create('crop_cycles', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code')->nullable();
            $table->string('crop_name');                        // culture : maïs, manioc, tomate…
            $table->string('variety')->nullable();              // variété
            $table->decimal('area_used_ha', 10, 4)->default(0); // surface réellement emblavée

            $table->date('planting_date');
            $table->date('expected_harvest_date')->nullable();
            $table->date('closing_date')->nullable();

            $table->decimal('seed_quantity', 12, 3)->nullable();
            $table->string('seed_unit')->nullable();
            $table->decimal('expected_yield_kg', 12, 3)->nullable();

            $table->string('status')->default('en_cours');      // cf. CropCycle::STATUS_*

            // Financier (mêmes intentions que Batch : intrants/semences vs revenus)
            $table->decimal('total_acquisition_cost', 14, 2)->default(0); // semences + intrants initiaux
            $table->decimal('additional_costs', 14, 2)->default(0);       // main d'œuvre, phyto, irrigation…
            $table->decimal('total_revenue', 14, 2)->default(0);

            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'status']);
            $table->index('plot_id');
        });

        // ─── RÉCOLTES ───
        Schema::create('harvests', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crop_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            $table->date('harvest_date');
            $table->decimal('quantity', 12, 3)->default(0);     // quantité récoltée nette
            $table->string('unit')->default('kg');
            $table->decimal('loss_quantity', 12, 3)->default(0); // pertes / déchets
            $table->string('quality')->default('bon');           // cf. Harvest::QUALITY_*

            // Intégration stock (optionnelle) : une récolte peut alimenter le stock.
            $table->boolean('synced_to_stock')->default(false);
            $table->string('stock_item_name')->nullable();
            $table->decimal('unit_price', 14, 2)->nullable();    // valorisation au kg

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'harvest_date']);
            $table->index('crop_cycle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harvests');
        Schema::dropIfExists('crop_cycles');
        Schema::dropIfExists('plots');
    }
};
