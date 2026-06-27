<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unification des achats d'aliments dans le registre fournisseurs (AP).
 *
 * - posts_expense : un achat « normal » poste une dépense au P&L à la validation.
 *   Les achats d'ALIMENTS ne le font PAS (false) : leur coût est déjà compté dans
 *   la marge des lots (feedPurchases.sum) → éviter le double comptage.
 * - feed_purchase_id : lien de traçabilité vers le FeedPurchase d'origine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->boolean('posts_expense')->default(true)->after('expense_id');
            $table->foreignId('feed_purchase_id')->nullable()->after('posts_expense')
                ->constrained('feed_purchases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('feed_purchase_id');
            $table->dropColumn('posts_expense');
        });
    }
};
