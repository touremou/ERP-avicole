<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RECETTES DE TRANSFORMATION (module: cultures).
 *
 * Une recette standardise une opération d'agro-transformation : intrants
 * attendus (matières premières), produit fini visé, rendement de référence et
 * durée de conservation. Pendant végétal d'une « Formula » de provenderie.
 * Une transformation (CropTransformation) peut être lancée depuis une recette
 * (crop_recipe_id), qui pré-remplit type/produit/rendement/péremption.
 *
 *   recette (crop_recipe) ── 1,n ──> ingrédient (crop_recipe_item)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_recipes', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code')->nullable();
            $table->string('name');                                  // « Gari de manioc »
            $table->string('transformation_type')->default('autre'); // cf. CropTransformation::TYPES
            $table->string('output_product');                        // produit fini visé
            $table->string('output_unit')->default('kg');
            $table->decimal('expected_yield_percent', 6, 2)->nullable(); // rendement de référence
            $table->unsignedSmallInteger('shelf_life_days')->nullable(); // durée de conservation
            $table->decimal('estimated_cost', 14, 2)->nullable();    // coût de transfo de référence
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'is_active']);
        });

        Schema::create('crop_recipe_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('crop_recipe_id')->constrained('crop_recipes')->cascadeOnDelete();

            $table->string('input_product');                        // matière première
            $table->decimal('quantity', 12, 3)->default(0);
            $table->string('unit')->default('kg');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('crop_recipe_id');
        });

        // Une transformation peut découler d'une recette.
        Schema::table('crop_transformations', function (Blueprint $table) {
            $table->foreignId('crop_recipe_id')->nullable()->after('crop_cycle_id')
                ->constrained('crop_recipes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crop_transformations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crop_recipe_id');
        });

        Schema::dropIfExists('crop_recipe_items');
        Schema::dropIfExists('crop_recipes');
    }
};
