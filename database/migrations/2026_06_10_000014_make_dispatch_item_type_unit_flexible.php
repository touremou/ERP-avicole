<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aligne la taxonomie des lignes d'expédition sur celle des ventes
 * (multiespèces). product_type / unit passent d'ENUM volaille figé à
 * string : on peut expédier des animaux vifs de toute espèce, de la
 * viande/carcasse, du lait, etc. Valeurs autorisées pilotées côté
 * applicatif (DispatchController), comme pour sale_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_items', function (Blueprint $table) {
            $table->string('product_type', 40)->change();
            $table->string('unit', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_items', function (Blueprint $table) {
            $table->enum('product_type', [
                'oeufs', 'volaille_vivante', 'volaille_abattue',
                'fumier', 'aliment', 'materiel', 'autre',
            ])->change();
            $table->enum('unit', ['alveole', 'unite', 'kg', 'piece', 'sac', 'voyage'])->change();
        });
    }
};
