<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3 — Magasin au terrain : uuid client sur les ajustements d'inventaire et
 * les réceptions d'aliment, pour l'idempotence du push offline. Un comptage
 * rejoué ne doit pas ajuster deux fois le stock, et une réception rejouée ne
 * doit pas créditer le magasin (ni la dette fournisseur) en double.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        Schema::table('feed_purchases', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        Schema::table('feed_purchases', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
