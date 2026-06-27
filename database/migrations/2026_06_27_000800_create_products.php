<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue des ARTICLES vendables (côté commerce). Donne une identité aux
 * produits réellement vendus (œuf calibre L, poulet 1,8 kg, carcasse découpée…)
 * avec photo, prix de base et catégorie — base d'un tarif par article et d'une
 * sélection guidée au point de vente. Complète la vente en saisie libre, sans
 * la remplacer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('product_type'); // catégorie commerciale (oeufs, carcasse…)
            $table->string('unit')->default('unite');
            $table->decimal('base_price', 12, 2)->default(0);
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['farm_id', 'is_active']);
            $table->index('product_type');
        });

        // Lien optionnel : quelle ligne de vente correspond à quel article catalogue.
        if (Schema::hasTable('sale_items') && ! Schema::hasColumn('sale_items', 'product_ref_id')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->foreignId('product_ref_id')->nullable()->after('product_id')->constrained('products')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_items', 'product_ref_id')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_ref_id');
            });
        }
        Schema::dropIfExists('products');
    }
};
