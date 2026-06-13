<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corrige la liste des catégories de stock affichée dans Paramètres >
     * Stocks : la valeur seedée ("oeufs,aliment,medicament,materiel,produits_finis")
     * ne correspond pas aux catégories réellement utilisées par le module
     * Stocks (cf. resources/views/stocks/index.blade.php) : oeufs, lait,
     * conso, produits_finis, litieres, materiels.
     */
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        DB::table('settings')
            ->where('group', 'stocks')
            ->where('key', 'categories')
            ->update([
                'value'      => 'oeufs,lait,conso,litieres,materiels,produits_finis',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) return;

        DB::table('settings')
            ->where('group', 'stocks')
            ->where('key', 'categories')
            ->update([
                'value'      => 'oeufs,aliment,medicament,materiel,produits_finis',
                'updated_at' => now(),
            ]);
    }
};
