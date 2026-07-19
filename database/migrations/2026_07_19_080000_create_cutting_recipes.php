<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 2 (refonte désassemblage) — recettes de désassemblage (BOM inversée) :
 * un article brut (carcasse) génère des co-produits (cuisses, ailes, blancs),
 * des sous-produits (abats, carcasses de dos) et des déchets. Paramétrable
 * par ferme et par famille d'espèce ; en l'absence de recette, la nomenclature
 * de config/butchery.php reste le repli (rétrocompat totale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutting_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            // Clé de famille de config/butchery.php (volaille, porcin...).
            $table->string('species_family', 40)->default('volaille');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Une seule recette ACTIVE par ferme+famille (résolution simple).
            $table->index(['farm_id', 'species_family', 'is_active']);
        });

        Schema::create('cutting_recipe_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cutting_recipe_id')->constrained()->cascadeOnDelete();
            $table->string('cut_code', 40);          // code stable (stocké sur CutProduct.product_type)
            $table->string('label');                  // libellé affiché / nom d'article
            // BOM inversée : nature de l'extrant — pilote la valorisation (Lot 3) :
            // co_produit = porte le coût au prorata de sa valeur ; sous_produit =
            // valeur de récupération faible ; dechet = coût nul, pesé pour la
            // balance de masse et le registre déchets.
            $table->enum('output_type', ['co_produit', 'sous_produit', 'dechet'])->default('co_produit');
            $table->decimal('expected_yield_percent', 5, 2)->nullable(); // % du poids entré
            // Coefficient de valeur marchande relative (prix de référence /kg) :
            // base de la répartition des coûts conjoints (1 kg de filet n'a pas
            // le coût d'1 kg de pattes). Nullable → répartition au kg (rétrocompat).
            $table->decimal('value_coefficient', 10, 2)->nullable();
            $table->string('default_destination', 30)->default('stock_frais');
            $table->string('default_packaging', 20)->nullable();
            $table->string('default_calibre', 40)->nullable();
            $table->boolean('is_default')->default(true); // ligne pré-remplie au formulaire
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['cutting_recipe_id', 'cut_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cutting_recipe_lines');
        Schema::dropIfExists('cutting_recipes');
    }
};
