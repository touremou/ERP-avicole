<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lien optionnel article catalogue → article de stock physique. Quand il est
 * défini, la vente de l'article décrémente automatiquement ce stock (via le
 * chemin de déstockage existant), et le catalogue/POS affiche la disponibilité
 * réelle. Un article non lié (service, vente sur pied au cas par cas) reste
 * vendable sans impact stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'stock_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('stock_id')->nullable()->after('product_type')->constrained('stocks')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'stock_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropConstrainedForeignId('stock_id');
            });
        }
    }
};
