<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        $tables = ['batches', 'incubations', 'health_checks', 'daily_checks', 'egg_productions'];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                // UUID pour la synchro offline (identifiant unique universel)
                if (!Schema::hasColumn($table->getTable(), 'uuid')) {
                    $table->uuid('uuid')->nullable()->after('id')->index();
                }
                // Flags de synchronisation
                if (!Schema::hasColumn($table->getTable(), 'is_synced')) {
                    $table->boolean('is_synced')->default(true)->index();
                }
                if (!Schema::hasColumn($table->getTable(), 'last_sync_at')) {
                    $table->timestamp('last_sync_at')->nullable();
                }
                // SoftDeletes pour ne jamais rien perdre (Rigueur ERP)
                if (!Schema::hasColumn($table->getTable(), 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void {
        // En mode industriel, on évite de supprimer des colonnes en production, 
        // mais voici la logique de rollback au cas où.
        $tables = ['batches', 'incubations', 'health_checks', 'daily_checks', 'egg_productions'];
        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['uuid', 'is_synced', 'last_sync_at']);
                $table->dropSoftDeletes();
            });
        }
    }
};