<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();

            // Produit vendu
            $table->enum('product_type', [
                'oeufs',              // Œufs calibrés (S, M, L, XL)
                'volaille_vivante',   // Poulet/poule sur pied
                'volaille_abattue',   // Poulet abattu (au kg)
                'fumier',             // Fumier (sac ou voyage)
                'aliment',            // Revente d'aliment (Chair Finition, etc.)
                'materiel',           // Matériel avicole
                'autre',
            ]);
            $table->string('product_name');                                     // "Œufs calibre L", "Poulet vif", etc.
            $table->unsignedBigInteger('product_id')->nullable();               // FK stocks.id (oeufs, aliment, materiel)
            $table->unsignedBigInteger('batch_id')->nullable();                 // FK batches.id (volaille — traçabilité)

            // Quantité & prix
            $table->decimal('quantity', 12, 2);
            $table->enum('unit', ['alveole', 'unite', 'kg', 'piece', 'sac', 'voyage'])->default('piece');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 14, 2);                                    // quantity * unit_price

            $table->timestamps();

            $table->index('product_type');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
