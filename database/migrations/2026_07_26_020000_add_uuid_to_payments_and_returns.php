<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2 — Argent au terrain : uuid client sur les encaissements et les retours,
 * pour l'idempotence du push offline. Un livreur qui encaisse hors réseau ne
 * doit JAMAIS créer deux paiements si l'opération est rejouée (le double
 * encaissement fausse le solde client et la caisse).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
