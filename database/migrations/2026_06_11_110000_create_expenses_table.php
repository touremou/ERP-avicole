<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registre des dépenses — saisie manuelle des charges ponctuelles (cash).
 *
 * Contexte Afrique : l'essentiel des petites dépenses (carburant, transport,
 * réparations, fournitures, frais divers) se règle en espèces et échappe
 * souvent à toute traçabilité. Ce registre les capture pour qu'elles entrent
 * dans le résultat financier (P&L) et, si rattachées à un lot, dans sa marge.
 *
 * Choix de typage : category / payment_method / status sont des VARCHAR (et
 * non des ENUM) pour rester extensibles sans migration — même leçon que la
 * correction des colonnes `type` multi-espèces (ENUM trop restrictif →
 * "Data truncated for column").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            // ─── Offline-first (cohérent avec sales / batches / stock_movements) ───
            $table->uuid('uuid')->nullable()->unique();
            $table->boolean('is_synced')->default(true);
            $table->timestamp('last_sync_at')->nullable();

            $table->string('reference')->unique();

            // Multi-ferme + rattachement optionnel à un lot (charge directe).
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('category', 50);            // carburant, transport, entretien...
            $table->string('label');                   // libellé court de la dépense
            $table->decimal('amount', 14, 2);
            $table->date('expense_date');
            $table->string('payment_method', 30)->default('especes');

            // Mécanisme de confiance : en_attente → valide / annule.
            // Seules les dépenses validées entrent dans le résultat financier.
            $table->string('status', 20)->default('en_attente');

            $table->string('supplier_name')->nullable(); // bénéficiaire / fournisseur (cash)
            $table->text('notes')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'expense_date']);
            $table->index(['category', 'status']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
