<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ajoute le socle offline-first à la table `sales` :
 * - uuid         : identifiant stable généré côté terrain (idempotence de synchro)
 * - is_synced    : drapeau de synchronisation (true par défaut pour les ventes
 *                  créées en ligne)
 * - last_sync_at : horodatage de la dernière réconciliation
 *
 * Permet d'enregistrer une vente rapide hors-ligne (IndexedDB) puis de la
 * réconcilier sans doublon via l'uuid (cf. SyncController::reconcileSale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'uuid')) {
                $table->uuid('uuid')->nullable()->after('id');
            }
            if (! Schema::hasColumn('sales', 'is_synced')) {
                $table->boolean('is_synced')->default(true)->after('uuid');
            }
            if (! Schema::hasColumn('sales', 'last_sync_at')) {
                $table->timestamp('last_sync_at')->nullable()->after('is_synced');
            }
        });

        // Backfill : les ventes existantes reçoivent un uuid (et restent synced).
        DB::table('sales')->whereNull('uuid')->orderBy('id')->each(function ($sale) {
            DB::table('sales')->where('id', $sale->id)->update(['uuid' => (string) Str::uuid()]);
        });

        // Index unique sur uuid (garantit l'idempotence de la réconciliation).
        Schema::table('sales', function (Blueprint $table) {
            $table->unique('uuid', 'sales_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'uuid')) {
                $table->dropUnique('sales_uuid_unique');
            }
            $table->dropColumn(['uuid', 'is_synced', 'last_sync_at']);
        });
    }
};
