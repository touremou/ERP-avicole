<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surface les catégories de stock végétales (« recoltes », « intrants ») dans
 * l'écran Stocks pour les installations ayant PERSONNALISÉ la liste affichée
 * (paramètre stocks.categories).
 *
 * Rappel : quand stocks.categories est vide, Stock::activeCategories() retombe
 * sur TOUTES les catégories connues — les nouvelles apparaissent donc déjà.
 * Le problème ne concerne QUE les installations où ce paramètre a été
 * explicitement restreint : on y ajoute les deux catégories sans toucher au
 * reste (non destructif), pour que les récoltes/intrants synchronisés au stock
 * restent visibles.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')
            ->where('group', 'stocks')
            ->where('key', 'categories')
            ->whereNull('farm_id')
            ->first();

        // Paramètre absent ou vide → fallback "toutes catégories" déjà actif.
        if (! $row || trim((string) $row->value) === '') {
            return;
        }

        $current = array_values(array_filter(array_map('trim', explode(',', (string) $row->value))));

        foreach (['recoltes', 'intrants'] as $cat) {
            if (! in_array($cat, $current, true)) {
                $current[] = $cat;
            }
        }

        DB::table('settings')
            ->where('id', $row->id)
            ->update(['value' => implode(',', $current), 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')
            ->where('group', 'stocks')
            ->where('key', 'categories')
            ->whereNull('farm_id')
            ->first();

        if (! $row || trim((string) $row->value) === '') {
            return;
        }

        $current = array_values(array_filter(
            array_map('trim', explode(',', (string) $row->value)),
            fn ($c) => ! in_array($c, ['recoltes', 'intrants'], true)
        ));

        DB::table('settings')
            ->where('id', $row->id)
            ->update(['value' => implode(',', $current), 'updated_at' => now()]);
    }
};
