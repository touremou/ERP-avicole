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
                Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->string('category'); // oeufs, materiels, machines, litieres, consommables
            $table->string('item_name'); // ex: Alvéoles, Seringues, Boites XL, Copeaux
            $table->string('unit')->default('unité'); // kg, unité, sac, litre
            $table->decimal('current_quantity', 12, 2)->default(0);
            $table->decimal('alert_threshold', 12, 2)->default(5); // Seuil d'alerte
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
