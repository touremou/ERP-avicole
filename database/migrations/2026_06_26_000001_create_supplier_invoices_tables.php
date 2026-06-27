<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comptabilité fournisseurs (dettes / accounts payable).
 *
 * Symétrique des ventes : un AchatFournisseur (supplier_invoices) est un DÉBIT
 * (ce que l'on doit), réglé par des PaiementFournisseur (supplier_payments,
 * montant signé — un avoir fournisseur est négatif). La dette d'un fournisseur =
 * Σ achats (hors annulés) − Σ paiements.
 *
 * SOURCE UNIQUE P&L : à la VALIDATION, l'achat poste UNE dépense « valide » au
 * registre Dépenses (expense_id). Les paiements ne font que solder la dette —
 * ils ne ré-imputent rien (zéro double comptage, cf. invariant carburant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();          // échéance (aging des dettes)
            $table->string('category', 50)->default('divers');
            $table->string('label');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('status', 20)->default('brouillon'); // brouillon / valide / annule
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['farm_id', 'invoice_date']);
            $table->index(['provider_id', 'status']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2); // signé : négatif = avoir fournisseur
            $table->date('payment_date');
            $table->string('method', 30)->default('especes');
            $table->string('reference')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['farm_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_invoices');
    }
};
