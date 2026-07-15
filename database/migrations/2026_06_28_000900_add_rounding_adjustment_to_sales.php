<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arrondi de caisse (coupures non disponibles en Guinée) : on conserve la
 * trace de l'ajustement appliqué au total payable. rounding_adjustment =
 * total_amount (arrondi) − total brut marchandise. Positif si on a arrondi
 * vers le haut, négatif vers le bas. Utile pour la transparence comptable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('rounding_adjustment', 14, 2)->default(0)->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('rounding_adjustment'));
    }
};
