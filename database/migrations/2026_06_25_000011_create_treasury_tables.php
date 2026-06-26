<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comptes de trésorerie (Caisse, Mobile Money, Banque) + grand-livre.
 *
 * Donne le SOLDE par canal et trace chaque mouvement (entrée/sortie/transfert),
 * pour répondre à « combien ai-je en caisse, sur OM, en banque ? ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 20)->default('caisse'); // caisse | mobile_money | banque | autre
            $table->decimal('opening_balance', 16, 2)->default(0);
            $table->decimal('current_balance', 16, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['farm_id', 'is_active']);
        });

        Schema::create('treasury_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('treasury_account_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 4); // in | out
            $table->decimal('amount', 16, 2);
            $table->date('transaction_date');
            $table->string('category', 30)->default('manuel'); // manuel | transfert | vente | depense | depot...
            $table->string('description', 255)->nullable();
            $table->string('reference')->nullable();
            // Pour un transfert : l'autre compte impliqué (in ↔ out appariés).
            $table->foreignId('counterpart_account_id')->nullable()->constrained('treasury_accounts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['treasury_account_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_transactions');
        Schema::dropIfExists('treasury_accounts');
    }
};
