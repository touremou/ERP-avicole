<?php

/**
 * MIGRATION CORRECTIVE — TABLES ANNEXES MODULE LOTS
 *
 * Corrections sur : buildings, batch_tasks, health_checks, egg_productions
 * Ajout du support de scission de lot (parent_batch_id).
 *
 * Bugs corrigés : S-08 (split de lot), S-15 (batch_tasks sans archivage)
 * Améliorations : index de performance, colonnes manquantes
 *
 * @see AUDIT_MODULE_LOTS.md — Sections S-08, S-15
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────
        // 1. BATCHES : SUPPORT SCISSION (SPLIT)
        // ─────────────────────────────────────────────
        // Permet de tracer qu'un lot est issu de la scission d'un autre
        Schema::table('batches', function (Blueprint $table) {
            if (! Schema::hasColumn('batches', 'parent_batch_id')) {
                $table->unsignedBigInteger('parent_batch_id')->nullable()->after('id');

                // FK auto-référencée (nullOnDelete car si le parent est supprimé,
                // l'enfant reste autonome)
                // Note: SQLite doesn't support adding FK after creation, skip on SQLite
                if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                    $table->foreign('parent_batch_id')
                          ->references('id')->on('batches')
                          ->nullOnDelete();
                }
            }
        });

        // ─────────────────────────────────────────────
        // 2. BATCH_TASKS : COLONNES D'ARCHIVAGE
        // ─────────────────────────────────────────────
        // Pour ne plus perdre l'historique lors de la resynchronisation
        // du planning sanitaire (SanitarySchedulerService::syncSchedule)
        Schema::table('batch_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('batch_tasks', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            }

            if (! Schema::hasColumn('batch_tasks', 'cancellation_reason')) {
                $table->string('cancellation_reason')->nullable()->after('cancelled_at');
            }
        });

        // Index for batch_tasks (idempotent via try/catch + cross-DB check)
        if (! $this->indexExists('batch_tasks', 'idx_batch_tasks_planned')) {
            try {
                Schema::table('batch_tasks', function (Blueprint $table) {
                    $table->index(['batch_id', 'planned_date', 'is_completed'], 'idx_batch_tasks_planned');
                });
            } catch (\Throwable $e) {
                // Index already exists — silently ignore
            }
        }

        // ─────────────────────────────────────────────
        // 3. BUILDINGS : INDEX MANQUANTS
        // ─────────────────────────────────────────────
        if (! $this->indexExists('buildings', 'idx_buildings_status')) {
            try {
                Schema::table('buildings', function (Blueprint $table) {
                    $table->index('status', 'idx_buildings_status');
                });
            } catch (\Throwable $e) {
                // Index already exists — silently ignore
            }
        }

        // ─────────────────────────────────────────────
        // 4. HEALTH_CHECKS : INDEX PERFORMANCE
        // ─────────────────────────────────────────────
        $healthIndexes = [
            'idx_health_checks_batch_date' => ['batch_id', 'intervention_date'],
            'idx_health_checks_type'       => ['type'],
        ];

        foreach ($healthIndexes as $name => $columns) {
            if (! $this->indexExists('health_checks', $name)) {
                try {
                    Schema::table('health_checks', function (Blueprint $table) use ($columns, $name) {
                        $table->index($columns, $name);
                    });
                } catch (\Throwable $e) {
                    // Index already exists — silently ignore
                }
            }
        }

        // ─────────────────────────────────────────────
        // 5. EGG_PRODUCTIONS : INDEX PERFORMANCE
        // ─────────────────────────────────────────────
        $eggIndexes = [
            'idx_egg_productions_batch_date' => ['batch_id', 'production_date'],
            'idx_egg_productions_graded'     => ['is_graded'],
        ];

        foreach ($eggIndexes as $name => $columns) {
            if (! $this->indexExists('egg_productions', $name)) {
                try {
                    Schema::table('egg_productions', function (Blueprint $table) use ($columns, $name) {
                        $table->index($columns, $name);
                    });
                } catch (\Throwable $e) {
                    // Index already exists — silently ignore
                }
            }
        }

        // ─────────────────────────────────────────────
        // 6. FEED_PURCHASES : INDEX PERFORMANCE
        // ─────────────────────────────────────────────
        if (! $this->indexExists('feed_purchases', 'idx_feed_purchases_batch')) {
            try {
                Schema::table('feed_purchases', function (Blueprint $table) {
                    $table->index(['batch_id', 'purchase_date'], 'idx_feed_purchases_batch');
                });
            } catch (\Throwable $e) {
                // Index already exists — silently ignore
            }
        }

        // ─────────────────────────────────────────────
        // 7. STOCKS & STOCK_MOVEMENTS : INDEX PERFORMANCE
        // ─────────────────────────────────────────────
        // Note: MySQL prefix index for long varchar columns — skip on SQLite
        if (! $this->indexExists('stocks', 'idx_stocks_category_name')) {
            try {
                $driver = Schema::getConnection()->getDriverName();
                if ($driver === 'sqlite') {
                    Schema::table('stocks', function (Blueprint $table) {
                        $table->index(['category', 'item_name'], 'idx_stocks_category_name');
                    });
                } else {
                    // MySQL/MariaDB: use prefix index to avoid key length limit
                    DB::statement('ALTER TABLE `stocks` ADD INDEX `idx_stocks_category_name` (`category`(50), `item_name`(100))');
                }
            } catch (\Throwable $e) {
                // Index already exists — silently ignore
            }
        }

        if (! $this->indexExists('stock_movements', 'idx_stock_movements_stock_created')) {
            try {
                Schema::table('stock_movements', function (Blueprint $table) {
                    $table->index(['stock_id', 'created_at'], 'idx_stock_movements_stock_created');
                });
            } catch (\Throwable $e) {
                // Index already exists — silently ignore
            }
        }
    }

    public function down(): void
    {
        // Suppression des colonnes ajoutées
        Schema::table('batches', function (Blueprint $table) {
            if (Schema::hasColumn('batches', 'parent_batch_id')) {
                if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                    $table->dropForeign(['parent_batch_id']);
                }
                $table->dropColumn('parent_batch_id');
            }
        });

        Schema::table('batch_tasks', function (Blueprint $table) {
            $columns = ['cancelled_at', 'cancellation_reason'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('batch_tasks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if ($this->indexExists('batch_tasks', 'idx_batch_tasks_planned')) {
            try {
                Schema::table('batch_tasks', function (Blueprint $table) {
                    $table->dropIndex('idx_batch_tasks_planned');
                });
            } catch (\Throwable $e) { }
        }

        // Suppression des index de performance (sans perte de données)
        $indexDrops = [
            'buildings'        => ['idx_buildings_status'],
            'health_checks'    => ['idx_health_checks_batch_date', 'idx_health_checks_type'],
            'egg_productions'  => ['idx_egg_productions_batch_date', 'idx_egg_productions_graded'],
            'feed_purchases'   => ['idx_feed_purchases_batch'],
            'stocks'           => ['idx_stocks_category_name'],
            'stock_movements'  => ['idx_stock_movements_stock_created'],
        ];

        foreach ($indexDrops as $tableName => $indexes) {
            foreach ($indexes as $name) {
                if ($this->indexExists($tableName, $name)) {
                    try {
                        Schema::table($tableName, function (Blueprint $table) use ($name) {
                            $table->dropIndex($name);
                        });
                    } catch (\Throwable $e) {
                        // Ignore
                    }
                }
            }
        }
    }

    // ─────────────────────────────────────────────
    // HELPERS CROSS-DB (MySQL + SQLite)
    // ─────────────────────────────────────────────

    /**
     * Vérifie si un index existe — compatible MySQL ET SQLite.
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
};
