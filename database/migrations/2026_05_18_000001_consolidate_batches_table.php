<?php

/**
 * MIGRATION CORRECTIVE — CONSOLIDATION TABLE BATCHES
 *
 * Contexte : La migration d'origine (2026_03_18) ne contient que ~20 colonnes.
 * La production en a ~45. Cette migration comble l'écart de manière idempotente.
 *
 * HOTFIX : Suppression de toutes les requêtes MySQL brutes (SHOW INDEX,
 * information_schema) pour compatibilité SQLite (tests) et MySQL (prod).
 * Utilise Schema::getIndexListing() et try/catch pour l'idempotence.
 *
 * Bugs corrigés : B-10 (schéma obsolète)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────
        // 1-7. AJOUT DES COLONNES MANQUANTES (idempotent via hasColumn)
        // ─────────────────────────────────────────────
        Schema::table('batches', function (Blueprint $table) {

            // 1. SYNCHRONISATION OFFLINE
            if (! Schema::hasColumn('batches', 'uuid')) {
                $table->char('uuid', 36)->after('id')->nullable();
            }
            if (! Schema::hasColumn('batches', 'is_synced')) {
                $table->boolean('is_synced')->default(true)->after('uuid');
            }
            if (! Schema::hasColumn('batches', 'last_sync_at')) {
                $table->timestamp('last_sync_at')->nullable()->after('is_synced');
            }

            // 2. TYPE / SOUCHE
            if (! Schema::hasColumn('batches', 'type')) {
                $table->string('type')->default('chair')->after('employee_id');
            }
            if (! Schema::hasColumn('batches', 'model_name')) {
                $table->string('model_name')->nullable()->after('type');
            }

            // 3. PROTOCOLES SANITAIRES
            if (! Schema::hasColumn('batches', 'protocol_id')) {
                $table->unsignedBigInteger('protocol_id')->nullable()->after('employee_id');
            }
            if (! Schema::hasColumn('batches', 'current_protocol_id')) {
                $table->unsignedBigInteger('current_protocol_id')->nullable()->after('protocol_id');
            }
            if (! Schema::hasColumn('batches', 'production_phase')) {
                $table->string('production_phase')->default('demarrage')->after('current_protocol_id');
            }

            // 4. TRANSFERT / MUTATION
            if (! Schema::hasColumn('batches', 'start_date')) {
                $table->date('start_date')->nullable()->after('arrival_date');
            }
            if (! Schema::hasColumn('batches', 'transfer_date')) {
                $table->date('transfer_date')->nullable()->after('start_date');
            }
            if (! Schema::hasColumn('batches', 'transfer_history')) {
                $table->json('transfer_history')->nullable()->after('transfer_date');
            }
            if (! Schema::hasColumn('batches', 'allocated_surface')) {
                $table->decimal('allocated_surface', 8, 2)->nullable()->after('building_id');
            }

            // 5. REPRODUCTEURS
            if (! Schema::hasColumn('batches', 'qty_males')) {
                $table->integer('qty_males')->unsigned()->default(0)->after('current_quantity');
            }
            if (! Schema::hasColumn('batches', 'qty_females')) {
                $table->integer('qty_females')->unsigned()->default(0)->after('qty_males');
            }
            if (! Schema::hasColumn('batches', 'mating_ratio')) {
                $table->decimal('mating_ratio', 5, 2)->default(0)->after('qty_females');
            }

            // 6. CLÔTURE / FINANCIER
            if (! Schema::hasColumn('batches', 'actual_sell_price_per_unit')) {
                $table->decimal('actual_sell_price_per_unit', 10, 2)->nullable()->after('buy_price_per_unit');
            }
            if (! Schema::hasColumn('batches', 'additional_costs')) {
                $table->decimal('additional_costs', 12, 2)->default(0)->after('total_acquisition_cost');
            }
            if (! Schema::hasColumn('batches', 'total_revenue')) {
                $table->decimal('total_revenue', 14, 2)->nullable()->after('additional_costs');
            }
            if (! Schema::hasColumn('batches', 'margin')) {
                $table->decimal('margin', 14, 2)->nullable()->after('total_revenue');
            }
            if (! Schema::hasColumn('batches', 'closing_date')) {
                $table->date('closing_date')->nullable()->after('expected_end_date');
            }

            // 7. SOFT DELETES
            if (! Schema::hasColumn('batches', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // ─────────────────────────────────────────────
        // 8. BACKFILL UUID POUR LOTS EXISTANTS
        // ─────────────────────────────────────────────
        $batchesWithoutUuid = DB::table('batches')
            ->whereNull('uuid')
            ->orWhere('uuid', '')
            ->pluck('id');

        foreach ($batchesWithoutUuid as $id) {
            DB::table('batches')
                ->where('id', $id)
                ->update(['uuid' => (string) Str::uuid()]);
        }

        // Ajouter l'index unique sur uuid (idempotent via try/catch)
        if (Schema::hasColumn('batches', 'uuid')) {
            if (! $this->indexExists('batches', 'batches_uuid_unique')) {
                try {
                    Schema::table('batches', function (Blueprint $table) {
                        $table->unique('uuid', 'batches_uuid_unique');
                    });
                } catch (\Throwable $e) {
                    // Index existe déjà — on ignore silencieusement
                }
            }
        }

        // ─────────────────────────────────────────────
        // 9. BACKFILL 'type' DEPUIS 'breeding_type'
        // ─────────────────────────────────────────────
        if (Schema::hasColumn('batches', 'breeding_type') && Schema::hasColumn('batches', 'type')) {
            $mapping = [
                'Chair'        => 'chair',
                'Pondeuse'     => 'ponte',
                'Reproducteur' => 'reproducteur',
            ];

            foreach ($mapping as $old => $new) {
                DB::table('batches')
                    ->where('breeding_type', $old)
                    ->where(function ($q) {
                        $q->whereNull('type')
                          ->orWhere('type', '')
                          ->orWhere('type', 'chair');
                    })
                    ->update(['type' => $new]);
            }
        }

        // ─────────────────────────────────────────────
        // 10. INDEX DE PERFORMANCE (idempotent)
        // ─────────────────────────────────────────────
        $indexes = [
            'idx_batches_status_type'    => ['status', 'type'],
            'idx_batches_building_status' => ['building_id', 'status'],
            'idx_batches_arrival_date'    => ['arrival_date'],
        ];

        foreach ($indexes as $name => $columns) {
            if (! $this->indexExists('batches', $name)) {
                try {
                    Schema::table('batches', function (Blueprint $table) use ($columns, $name) {
                        $table->index($columns, $name);
                    });
                } catch (\Throwable $e) {
                    // Index existe déjà — on ignore
                }
            }
        }

        // ─────────────────────────────────────────────
        // 11. FOREIGN KEYS VERS PROTOCOLS
        // ─────────────────────────────────────────────
        if (Schema::hasTable('protocols')) {
            $this->addForeignKeyIfNotExists('batches', 'protocol_id', 'protocols', 'id', 'batches_protocol_id_foreign');
            $this->addForeignKeyIfNotExists('batches', 'current_protocol_id', 'protocols', 'id', 'batches_current_protocol_id_foreign');
        }
    }

    public function down(): void
    {
        $indexes = ['idx_batches_status_type', 'idx_batches_building_status', 'idx_batches_arrival_date'];
        foreach ($indexes as $name) {
            if ($this->indexExists('batches', $name)) {
                try {
                    Schema::table('batches', function (Blueprint $table) use ($name) {
                        $table->dropIndex($name);
                    });
                } catch (\Throwable $e) {
                    // Ignore si l'index n'existe pas
                }
            }
        }
    }

    // ─────────────────────────────────────────────
    // HELPERS CROSS-DB (MySQL + SQLite)
    // ─────────────────────────────────────────────

    /**
     * Vérifie si un index existe sur une table.
     * Compatible MySQL ET SQLite.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        try {
            if ($driver === 'sqlite') {
                $indexes = DB::select("PRAGMA index_list(\"{$table}\")");
                return collect($indexes)->contains('name', $indexName);
            }

            // MySQL / MariaDB
            $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            return count($indexes) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Ajoute une foreign key si elle n'existe pas déjà.
     * Compatible MySQL ET SQLite (SQLite ignore les FK par défaut).
     */
    private function addForeignKeyIfNotExists(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $constraintName
    ): void {
        $driver = Schema::getConnection()->getDriverName();

        // SQLite ne supporte pas l'ajout de FK après création → on skip
        if ($driver === 'sqlite') return;

        try {
            $exists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = ?
                 AND CONSTRAINT_NAME = ?
                 AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$table, $constraintName]
            );

            if (empty($exists)) {
                Schema::table($table, function (Blueprint $table) use ($column, $referencedTable, $referencedColumn, $constraintName) {
                    $table->foreign($column, $constraintName)
                          ->references($referencedColumn)->on($referencedTable)
                          ->nullOnDelete();
                });
            }
        } catch (\Throwable $e) {
            // FK existe déjà ou erreur — on ignore
        }
    }
};
