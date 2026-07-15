<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonne `metadata` manquante sur feed_purchases.
 *
 * Le modèle FeedPurchase déclare `metadata` (fillable + cast array) et
 * CreateFeedPurchase y écrit bag_weight / conso_type / poultry_type, mais aucune
 * migration ne créait la colonne → échec sur install neuf / tests sqlite
 * (« table feed_purchases has no column named metadata »). La prod l'a déjà
 * (ajout ad-hoc) : on garde l'ajout idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('feed_purchases', 'metadata')) {
            Schema::table('feed_purchases', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('supplier');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('feed_purchases', 'metadata')) {
            Schema::table('feed_purchases', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }
    }
};
