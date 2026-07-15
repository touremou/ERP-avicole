<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remise globale sur une vente : pourcentage ou montant fixe, appliquée au
 * sous-total avant TVA. discount_amount est la remise effective calculée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales') || Schema::hasColumn('sales', 'discount_type')) return;

        Schema::table('sales', function (Blueprint $table) {
            $table->string('discount_type', 10)->default('none')->after('subtotal'); // none | percent | amount
            $table->decimal('discount_value', 12, 2)->default(0)->after('discount_type');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_value');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'discount_type')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn(['discount_type', 'discount_value', 'discount_amount']);
            });
        }
    }
};
