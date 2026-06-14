<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corrige la catégorie héritée « matiere_premiere » (créée par
     * 2026_04_02_173934_link_raw_materials_to_existing_stocks) en
     * « materiels » : « matiere_premiere » n'existe pas dans
     * Stock::CATEGORY_META ni dans le paramètre stocks.categories, donc les
     * articles concernés étaient invisibles dans tous les onglets de
     * l'index Stocks (cf. Stock::activeCategories()).
     */
    public function up(): void
    {
        if (! Schema::hasTable('stocks')) return;

        DB::table('stocks')
            ->where('category', 'matiere_premiere')
            ->update(['category' => 'materiels', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Non réversible : impossible de distinguer les enregistrements
        // d'origine « materiels » de ceux migrés depuis « matiere_premiere ».
    }
};
