<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rend la taxonomie des lignes de vente extensible (multiespèces).
 *
 * `product_type` et `unit` étaient des ENUM figés sur la volaille
 * (volaille_vivante / volaille_abattue, units kg/piece...). Pour vendre
 * des animaux vifs de toute espèce (moutons Tabaski, poisson, caprins),
 * de la viande/carcasse, du lait, etc., on les passe en `string` : les
 * valeurs autorisées sont désormais pilotées au niveau applicatif
 * (StoreSaleRequest), comme protocols.type. Évite un ALTER ENUM à chaque
 * nouvelle espèce/produit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('product_type', 40)->change();
            $table->string('unit', 20)->default('piece')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->enum('product_type', [
                'oeufs', 'volaille_vivante', 'volaille_abattue',
                'fumier', 'aliment', 'materiel', 'autre',
            ])->change();
            $table->enum('unit', ['alveole', 'unite', 'kg', 'piece', 'sac', 'voyage'])
                ->default('piece')->change();
        });
    }
};
