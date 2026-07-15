<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS « façon balance » (inspiration écran DIGI, audit UX 2026-07-04) :
 *
 * - sales.seller_employee_id : attribution NOMINATIVE de la vente à la
 *   vendeuse/au vendeur (employé), distincte du compte caissier connecté —
 *   plusieurs opérateurs partagent un même terminal. Nullable : sans module
 *   annuaire (palier), le POS fonctionne comme avant.
 *
 * - products.is_favorite : touches favorites de la grille caisse (premier
 *   écran de la balance). Le code PLU saisissable au pavé réutilise le champ
 *   `sku` existant du catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('seller_employee_id')->nullable()->after('user_id')
                ->constrained('employees')->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seller_employee_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_favorite');
        });
    }
};
