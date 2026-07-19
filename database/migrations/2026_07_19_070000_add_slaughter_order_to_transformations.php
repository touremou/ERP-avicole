<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traçabilité en cascade (refonte transformation, Lot 1) : une transformation
 * (fumage, marinade...) peut être rattachée à l'ordre d'abattage dont provient
 * sa matière première. Nullable : les transformations historiques et celles
 * saisies sans origine identifiable restent valides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transformations', function (Blueprint $table) {
            $table->foreignId('slaughter_order_id')->nullable()->after('farm_id')
                ->constrained('slaughter_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transformations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('slaughter_order_id');
        });
    }
};
