<?php

use App\Models\FuelPurchase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unifie le gasoil dans le registre des dépenses.
 *
 * Chaque achat de carburant (module Énergie) poste désormais une dépense
 * « carburant » valide : trésorerie tenue à un seul endroit, visible dans le
 * module Dépenses et le P&L. On ajoute le lien fuel_purchases → expenses, puis
 * on BACKFILL l'historique pour que les périodes passées conservent leur poste
 * Gasoil (le rapport lit désormais ce poste depuis les dépenses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_purchases', function (Blueprint $table) {
            $table->foreignId('expense_id')->nullable()->after('total_cost')
                ->constrained('expenses')->nullOnDelete();
        });

        // Backfill : une dépense carburant valide par achat historique non lié.
        FuelPurchase::withoutGlobalScopes()
            ->whereNull('expense_id')
            ->with('source')
            ->chunkById(200, function ($purchases) {
                foreach ($purchases as $purchase) {
                    $purchase->syncLedgerExpense();
                }
            });
    }

    public function down(): void
    {
        Schema::table('fuel_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
        });
    }
};
