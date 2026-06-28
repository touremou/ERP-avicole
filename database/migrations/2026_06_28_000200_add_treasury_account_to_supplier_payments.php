<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override du compte de trésorerie pour un règlement fournisseur (sinon le
 * mapping mode→compte s'applique). Le règlement est un DÉCAISSEMENT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->foreignId('treasury_account_id')->nullable()->after('method')
                ->constrained('treasury_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('treasury_account_id'));
    }
};
