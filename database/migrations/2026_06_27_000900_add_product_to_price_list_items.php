<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permet un prix par ARTICLE réel dans un groupe de prix (et non plus seulement
 * par catégorie). Un item de tarif est alors soit une ligne catégorie
 * (product_type, product_id null), soit une ligne article (product_id défini).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_price_list_items') && ! Schema::hasColumn('sale_price_list_items', 'product_id')) {
            Schema::table('sale_price_list_items', function (Blueprint $table) {
                // On ajoute d'abord la colonne article + l'index (liste, article)
                // AVANT de retirer l'ancienne unicité (liste, type). Sous MySQL,
                // la FK sale_price_list_id s'appuie sur le préfixe de cet index
                // unique : sans index de remplacement préalable, le dropUnique
                // échoue (erreur 1553). Le nouvel index (liste, article) fournit
                // ce support (sale_price_list_id en tête), rendant le drop possible.
                $table->foreignId('product_id')->nullable()->after('sale_price_list_id')->constrained('products')->cascadeOnDelete();
                $table->index(['sale_price_list_id', 'product_id']);

                // L'ancienne unicité (liste, type) empêchait de coexister un prix
                // catégorie ET des prix article du même type : on la retire au
                // profit d'une déduplication applicative (updateOrCreate).
                $table->dropUnique(['sale_price_list_id', 'product_type']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_price_list_items', 'product_id')) {
            Schema::table('sale_price_list_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_id');
            });
        }
    }
};
