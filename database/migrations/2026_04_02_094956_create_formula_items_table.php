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
        Schema::create('formula_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formula_id')->constrained()->onDelete('cascade');
            $table->foreignId('raw_material_id')->constrained();
            $table->decimal('quantity_kg', 10, 3); // Quantité requise pour le batch total
            $table->decimal('percentage', 5, 2); // Pourcentage (calculé ou saisi)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('formula_items');
    }
};
