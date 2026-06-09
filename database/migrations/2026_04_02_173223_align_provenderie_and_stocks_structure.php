<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    // 1. NETTOYAGE SÉCURISÉ DE FORMULA_ITEMS
    Schema::table('formula_items', function (Blueprint $table) {
        $columnsToDrop = ['alert_threshold', 'is_active', 'protein_rate', 'lysine_rate', 'calcium_rate'];
        
        foreach ($columnsToDrop as $column) {
            if (Schema::hasColumn('formula_items', $column)) {
                $table->dropColumn($column);
            }
        }
    });

    // 2. HARMONISATION DE LA PRÉCISION (3 DÉCIMALES)
    Schema::table('stocks', function (Blueprint $table) {
        $table->decimal('current_quantity', 15, 3)->change();
        $table->decimal('alert_threshold', 15, 3)->change();
    });

    Schema::table('stock_movements', function (Blueprint $table) {
        $table->decimal('quantity', 15, 3)->change();
    });

    // 3. CRÉATION DU PONT (LIAISON PROVENDERIE ↔ STOCKS)
    Schema::table('raw_materials', function (Blueprint $table) {
        // Ajout du lien seulement s'il n'existe pas
        if (!Schema::hasColumn('raw_materials', 'stock_id')) {
            $table->foreignId('stock_id')->nullable()->after('id')->constrained('stocks')->onDelete('set null');
        }
        
        // Sécurité sur les taux nutritionnels
        if (!Schema::hasColumn('raw_materials', 'protein_rate')) {
            $table->decimal('protein_rate', 5, 2)->default(0)->after('energy_kcal');
            $table->decimal('lysine_rate', 5, 2)->default(0)->after('protein_rate');
            $table->decimal('calcium_rate', 5, 2)->default(0)->after('lysine_rate');
            $table->decimal('alert_threshold', 15, 3)->default(100)->after('stock_qty');
        }
    });
}

    public function down(): void
    {
        // Logique inverse pour le rollback (Optionnel mais recommandé pour la rigueur)
        Schema::table('raw_materials', function (Blueprint $table) {
            $table->dropForeign(['stock_id']);
            $table->dropColumn(['stock_id', 'protein_rate', 'lysine_rate', 'calcium_rate', 'alert_threshold']);
        });
    }
};