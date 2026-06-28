<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intègre la trésorerie aux flux financiers : encaissements de vente (entrées)
 * et dépenses validées (sorties) alimentent automatiquement un compte.
 *
 *  - treasury_accounts.default_for_method : marque le compte « par défaut » d'un
 *    mode de paiement (espèces→Caisse, OM→Mobile Money…), surchargé au cas par cas.
 *  - treasury_transactions.source_* : lien polymorphe vers la pièce d'origine
 *    (Payment, Expense) → traçabilité, idempotence (anti double-comptage) et
 *    contre-passation à l'annulation.
 *  - payments / expenses.treasury_account_id : override explicite du compte cible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_accounts', function (Blueprint $table) {
            $table->string('default_for_method', 20)->nullable()->after('type');
        });

        Schema::table('treasury_transactions', function (Blueprint $table) {
            $table->string('source_type')->nullable()->after('reference');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('treasury_account_id')->nullable()->after('method')
                ->constrained('treasury_accounts')->nullOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('treasury_account_id')->nullable()->after('payment_method')
                ->constrained('treasury_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('treasury_account_id'));
        Schema::table('expenses', fn (Blueprint $t) => $t->dropConstrainedForeignId('treasury_account_id'));
        Schema::table('treasury_transactions', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });
        Schema::table('treasury_accounts', fn (Blueprint $t) => $t->dropColumn('default_for_method'));
    }
};
