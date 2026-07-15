<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groupes de prix de vente (tarifs différenciés : détail, grossiste, demi-gros…).
 * Chaque liste porte un prix par TYPE de produit (oeufs, carcasse, lait…), qui
 * pré-remplit la ligne de vente selon le tarif du client. L'opérateur peut
 * toujours surcharger le prix proposé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('sale_price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_price_list_id')->constrained()->cascadeOnDelete();
            $table->string('product_type');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['sale_price_list_id', 'product_type']);
        });

        if (Schema::hasTable('clients') && ! Schema::hasColumn('clients', 'price_list_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->foreignId('price_list_id')->nullable()->after('category')->constrained('sale_price_lists')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'price_list_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropConstrainedForeignId('price_list_id');
            });
        }
        Schema::dropIfExists('sale_price_list_items');
        Schema::dropIfExists('sale_price_lists');
    }
};
