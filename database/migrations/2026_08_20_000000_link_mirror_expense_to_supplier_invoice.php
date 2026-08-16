<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LE LIEN N'EXISTAIT QUE DANS UN SENS, ET TROP TARD.
 *
 * `SupplierInvoice::syncLedgerExpense()` crée la dépense miroir, PUIS écrit
 * `supplier_invoices.expense_id`. À l'instant où l'observateur des dépenses
 * s'exécute — donc à la création — l'achat ne porte pas encore le lien : rien ne
 * permet de reconnaître une dépense miroir au moment précis où il le faudrait.
 *
 * D'où cette colonne : la dépense sait, dès sa création, de quel achat elle est
 * le reflet. La trésorerie peut alors laisser passer la charge sans sortir
 * l'argent — le règlement s'en charge.
 *
 * ─── LA RÉPARATION DES DONNÉES ───
 *
 * Cette migration CORRIGE AUSSI L'EXISTANT, et elle change des soldes visibles.
 *
 * Chaque achat fournisseur validé a posté un décaissement par sa dépense
 * miroir, en plus de celui de son règlement. Le solde de trésorerie est donc
 * SOUS-ESTIMÉ du total de ces écritures. On les supprime et on rend leur
 * montant aux comptes concernés.
 *
 * On ne touche qu'aux écritures dont la source est une dépense reliée à un
 * achat fournisseur : ni les dépenses ordinaires, ni le carburant, ni les
 * règlements. Le nombre d'écritures reprises et le montant rendu sont écrits au
 * journal — c'est ce qu'il faudra comparer au relevé de caisse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('supplier_invoice_id')->nullable()->after('batch_id')
                ->constrained('supplier_invoices')->nullOnDelete();
        });

        // Rétablir le lien pour les dépenses miroir déjà en base.
        DB::table('supplier_invoices')->whereNotNull('expense_id')
            ->orderBy('id')
            ->chunkById(200, function ($invoices) {
                foreach ($invoices as $invoice) {
                    DB::table('expenses')->where('id', $invoice->expense_id)
                        ->update(['supplier_invoice_id' => $invoice->id]);
                }
            });

        // La réparation vit dans une action, pour être mesurable par un test :
        // une correction qui change des soldes visibles ne doit pas être le seul
        // morceau du correctif que personne ne vérifie.
        $bilan = app(\App\Actions\Treasury\ReverseMirrorExpensePostings::class)->execute();

        if ($bilan['count'] > 0) {
            \Illuminate\Support\Facades\Log::warning(
                'Trésorerie — décaissements en double repris : ' . $bilan['count']
                . ' écriture(s), ' . number_format($bilan['restored'], 2, ',', ' ')
                . ' rendus aux comptes. Les soldes de trésorerie remontent d\'autant.'
            );
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_invoice_id');
        });
    }
};
