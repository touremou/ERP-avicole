<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réception vif — origine et coût d'ACHAT.
 *
 * Jusqu'ici la réception n'enregistrait aucun coût : un abattoir qui ACHÈTE
 * des sujets vifs à un éleveur (ni lot interne, ni abattage à façon) voyait
 * ses ventes de carcasses sans coût d'acquisition → marge P&L gonflée et dette
 * fournisseur absente.
 *
 * On distingue l'origine (`achat` | `facon`) et, pour un achat, on capte le
 * coût. À la validation, une FACTURE FOURNISSEUR brouillon est générée (dette
 * envers l'éleveur) : elle poste la charge au P&L (compte SYSCOHADA 602) et
 * alimente le suivi des dettes/DPO — pendant exact de la vente de prestation
 * générée pour le façon (service_sale_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slaughter_receptions', function (Blueprint $t) {
            $t->string('origin', 10)->default('achat')->after('provider_id'); // achat | facon
            $t->string('purchase_basis', 20)->nullable()->after('origin');    // par_sujet | par_kg_vif | forfait
            $t->decimal('purchase_unit_price', 12, 2)->nullable()->after('purchase_basis'); // tarif OU forfait
            $t->decimal('purchase_total_cost', 14, 2)->nullable()->after('purchase_unit_price'); // calculé serveur
            $t->foreignId('supplier_invoice_id')->nullable()->after('purchase_total_cost')
                ->constrained('supplier_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('slaughter_receptions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('supplier_invoice_id');
            $t->dropColumn(['origin', 'purchase_basis', 'purchase_unit_price', 'purchase_total_cost']);
        });
    }
};
