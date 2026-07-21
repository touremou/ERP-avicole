<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correctif de données (cohérence POS) : les articles adossés à un stock
 * physique doivent vendre dans l'unité de CE stock. Les articles créés avec
 * une unité libre différente (ex. « unite » sur un stock en KG — découpes
 * transférées de l'abattoir) faisaient vendre des pièces au prix du kilo.
 * Le modèle force désormais l'alignement à chaque sauvegarde ; cette
 * migration réaligne l'existant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('products')
            ->join('stocks', 'stocks.id', '=', 'products.stock_id')
            ->whereColumn('products.unit', '!=', 'stocks.unit')
            ->get(['products.id as product_id', 'stocks.unit as stock_unit']);

        foreach ($rows as $row) {
            DB::table('products')->where('id', $row->product_id)->update(['unit' => $row->stock_unit]);
        }
    }

    public function down(): void
    {
        // Correctif de données : pas de retour arrière (l'ancienne unité
        // désalignée n'est pas conservée).
    }
};
