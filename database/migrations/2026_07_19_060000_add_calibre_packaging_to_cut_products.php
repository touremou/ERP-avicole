<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calibrage & conditionnement des découpes (lot C).
 *
 * Une découpe n'était que type + kg + pièces + prix. On ajoute le CALIBRE
 * (tranche de poids : S / M / L…), le CONDITIONNEMENT (vrac / barquette /
 * sachet — les abats « ensachés » deviennent une gamme à part entière) et le
 * nombre d'UVC (barquettes/sachets produits). Le stock produit fini distingue
 * alors chaque UVC (nom enrichi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cut_products', function (Blueprint $t) {
            $t->string('calibre', 40)->nullable()->after('quantity_pieces');   // "S", "M (0,9-1,2 kg)"…
            $t->string('packaging', 20)->default('vrac')->after('calibre');     // vrac | barquette | sachet
            $t->integer('pack_count')->nullable()->after('packaging');          // nb de barquettes/sachets (UVC)
        });
    }

    public function down(): void
    {
        Schema::table('cut_products', function (Blueprint $t) {
            $t->dropColumn(['calibre', 'packaging', 'pack_count']);
        });
    }
};
