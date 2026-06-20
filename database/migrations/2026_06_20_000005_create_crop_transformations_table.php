<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRANSFORMATION VÉGÉTALE (module: cultures).
 *
 * Décline le modèle `transformations` (abattoir : fumé/grillé/mariné) pour
 * l'agro-transformation végétale : manioc → gari/farine, fruits → jus/séché…
 * Entrée → sortie avec rendement, traçabilité optionnelle du cycle d'origine,
 * et intégration stock (déstockage de l'intrant, entrée du produit fini).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_transformations', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            // Traçabilité : cycle de culture d'origine (optionnel).
            $table->foreignId('crop_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            $table->string('batch_number')->nullable()->unique();
            $table->string('input_product');               // manioc, mangue, maïs…
            $table->string('output_product');              // gari, jus, farine…
            $table->string('transformation_type');         // sechage, mouture, jus, fermentation…

            $table->decimal('input_quantity', 12, 3)->default(0);
            $table->string('input_unit')->default('kg');
            $table->decimal('output_quantity', 12, 3)->default(0);
            $table->string('output_unit')->default('kg');
            $table->decimal('yield_percent', 6, 2)->default(0); // sortie / entrée × 100

            $table->date('production_date');
            $table->date('expiry_date')->nullable();

            $table->decimal('production_cost', 14, 2)->default(0); // main d'œuvre, énergie…
            $table->decimal('output_unit_price', 14, 2)->nullable(); // valorisation produit fini

            // Intégration stock.
            $table->boolean('consumed_from_stock')->default(false); // intrant déstocké ?
            $table->string('input_stock_item')->nullable();
            $table->boolean('synced_to_stock')->default(false);     // produit fini stocké ?
            $table->string('output_stock_item')->nullable();

            $table->string('status')->default('termine');  // en_cours | termine
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'production_date']);
            $table->index('crop_cycle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_transformations');
    }
};
