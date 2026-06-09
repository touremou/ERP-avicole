<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->unique();                              // Code unique : CLI-0001
            $table->string('name');
            $table->enum('type', ['particulier', 'entreprise'])->default('particulier');
            $table->enum('category', ['grossiste', 'detaillant', 'hotel_restaurant', 'revendeur', 'autre'])->default('detaillant');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('nif')->nullable();                                  // Numéro d'identification fiscale
            $table->string('rccm')->nullable();                                 // Registre du commerce
            $table->decimal('credit_limit', 14, 2)->default(0);                // Plafond crédit GNF
            $table->decimal('balance', 14, 2)->default(0);                      // Solde dû (positif = client doit)
            $table->enum('status', ['actif', 'suspendu', 'blackliste'])->default('actif');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category']);
            $table->index('balance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
