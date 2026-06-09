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
    Schema::create('feed_purchases', function (Blueprint $table) {
        $table->id();
        // Liaison avec le lot
        $table->foreignId('batch_id')->constrained()->onDelete('cascade');
        
        $table->date('purchase_date');
        $table->string('feed_type')->nullable(); // ex: Démarrage, Croissance, Finition
        $table->decimal('quantity', 10, 2); // Quantité achetée (kg)
        
        // Coût en GNF
        $table->decimal('unit_price', 15, 2); // Prix au KG
        $table->decimal('total_price', 15, 2); // Prix total de la facture
        
        $table->string('supplier')->nullable(); // Fournisseur
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_purchases');
    }
};
