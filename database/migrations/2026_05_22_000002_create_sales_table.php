<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();                              // BL-2026-000123 ou FAC-2026-000045
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();    // Vendeur
            $table->date('sale_date');
            $table->enum('type', ['bon_livraison', 'facture'])->default('bon_livraison');
            $table->enum('status', ['brouillon', 'valide', 'livre', 'annule'])->default('brouillon');

            // Montants
            $table->decimal('subtotal', 14, 2)->default(0);                    // HT
            $table->decimal('tax_rate', 5, 2)->default(0);                      // 0 ou 18
            $table->decimal('tax_amount', 14, 2)->default(0);                   // Montant TVA
            $table->decimal('total_amount', 14, 2)->default(0);                // TTC
            $table->decimal('paid_amount', 14, 2)->default(0);                 // Déjà payé
            $table->enum('payment_status', ['impaye', 'partiel', 'solde'])->default('impaye');

            // Livraison
            $table->enum('delivery_mode', ['sur_place', 'livraison'])->default('sur_place');
            $table->text('delivery_address')->nullable();
            $table->text('delivery_notes')->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'payment_status']);
            $table->index(['client_id', 'sale_date']);
            $table->index('sale_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
