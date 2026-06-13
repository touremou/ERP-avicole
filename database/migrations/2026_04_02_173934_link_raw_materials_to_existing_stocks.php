<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    $materials = DB::table('raw_materials')->get();

    foreach ($materials as $material) {
        // On cherche un item dans la table stocks qui a le même nom
        $stock = DB::table('stocks')
            ->where('item_name', 'LIKE', '%' . $material->name . '%')
            ->first();

        if ($stock) {
            DB::table('raw_materials')
                ->where('id', $material->id)
                ->update(['stock_id' => $stock->id]);
        } else {
            // Optionnel : Créer le stock s'il n'existe pas
            $newStockId = DB::table('stocks')->insertGetId([
                'category' => 'materiels',
                'item_name' => $material->name,
                'unit' => $material->unit ?? 'kg',
                'current_quantity' => $material->stock_qty,
                'alert_threshold' => 100.000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('raw_materials')
                ->where('id', $material->id)
                ->update(['stock_id' => $newStockId]);
        }
    }
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('existing_stocks', function (Blueprint $table) {
            //
        });
    }
};
