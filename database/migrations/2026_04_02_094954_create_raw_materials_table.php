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
        Schema::create('raw_materials', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('unit')->default('kg'); // kg, litre, unité
            $table->decimal('stock_qty', 15, 3)->default(0); // Précision au gramme
            $table->decimal('unit_cost', 12, 2)->default(0); // Coût moyen pondéré (GNF)
            $table->integer('alert_threshold')->default(100); // Seuil d'alerte stock bas
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('raw_materials');
    }
};
