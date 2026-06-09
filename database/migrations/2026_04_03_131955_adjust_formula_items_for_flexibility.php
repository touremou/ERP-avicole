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
        Schema::table('formula_items', function (Blueprint $table) {
            // On rend quantity_kg nullable car la recette est définie par son %
            $table->decimal('quantity_kg', 10, 3)->nullable()->change();
            
            // Optionnel : on s'assure que le pourcentage est bien en decimal(5,2)
            $table->decimal('percentage', 5, 2)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('formula_items', function (Blueprint $table) {
            $table->decimal('quantity_kg', 10, 3)->nullable(false)->change();
        });
    }
};
