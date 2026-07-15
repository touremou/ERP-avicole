<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Harmonisation : le sous-produit « Fumier » issu du ramassage de litière était
 * rangé en « produits_finis ». On le reclasse en « litieres » (cohérent avec le
 * type vendable « litieres »). Les mouvements référencent le stock par id : on
 * met simplement à jour la catégorie de l'article existant (historique préservé).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stocks')) return;

        DB::table('stocks')
            ->where('item_name', 'Fumier')
            ->where('category', 'produits_finis')
            ->update(['category' => 'litieres']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('stocks')) return;

        DB::table('stocks')
            ->where('item_name', 'Fumier')
            ->where('category', 'litieres')
            ->update(['category' => 'produits_finis']);
    }
};
