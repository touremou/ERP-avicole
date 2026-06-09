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
                $table->foreign('parent_batch_id')
                      ->references('id')->on('batches')
                      ->nullOnDelete();
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

            // Index pour les requêtes de planning (tâches à venir)
            $exists = collect(DB::select(
                "SHOW INDEX FROM batch_tasks WHERE Key_name = 'idx_batch_tasks_planned'"
            ))->isNotEmpty();

            if (! $exists) {
                $table->index(['batch_id', 'planned_date', 'is_completed'], 'idx_batch_tasks_planned');
            }
        });

        // ─────────────────────────────────────────────
        // 3. BUILDINGS : INDEX MANQUANTS
        // ─────────────────────────────────────────────
        Schema::table('buildings', function (Blueprint $table) {
            $exists = collect(DB::select(
                "SHOW INDEX FROM buildings WHERE Key_name = 'idx_buildings_status'"
            ))->isNotEmpty();

            if (! $exists) {
                $table->index('status', 'idx_buildings_status');
            }
        });

        // ─────────────────────────────────────────────
        // 4. HEALTH_CHECKS : INDEX PERFORMANCE
        // ─────────────────────────────────────────────
        Schema::table('health_checks', function (Blueprint $table) {
            $indexes = [
                'idx_health_checks_batch_date' => ['batch_id', 'intervention_date'],
                'idx_health_checks_type'       => ['type'],
            ];

            foreach ($indexes as $name => $columns) {
                $exists = collect(DB::select("SHOW INDEX FROM health_checks WHERE Key_name = ?", [$name]))->isNotEmpty();
                if (! $exists) {
                    $table->index($columns, $name);
                }
            }
        });

        // ─────────────────────────────────────────────
        // 5. EGG_PRODUCTIONS : INDEX PERFORMANCE
        // ─────────────────────────────────────────────
        Schema::table('egg_productions', function (Blueprint $table) {
            $indexes = [
                'idx_egg_productions_batch_date' => ['batch_id', 'production_date'],
                'idx_egg_productions_graded'     => ['is_graded'],
            ];

            foreach ($indexes as $name => $columns) {
                $exists = collect(DB::select("SHOW INDEX FROM egg_productions WHERE Key_name = ?", [$name]))->isNotEmpty();
                if (! $exists) {
                    $table->index($columns, $name);
                }
            }
        });

        // ─────────────────────────────────────────────
        // 6. FEED_PURCHASES : INDEX PERFORMANCE
        // ─────────────────────────────────────────────
        Schema::table('feed_purchases', function (Blueprint $table) {
            $exists = collect(DB::select(
                "SHOW INDEX FROM feed_purchases WHERE Key_name = 'idx_feed_purchases_batch'"
            ))->isNotEmpty();

            if (! $exists) {
                $table->index(['batch_id', 'purchase_date'], 'idx_feed_purchases_batch');
            }
        });

        // ─────────────────────────────────────────────
        // 7. STOCKS & STOCK_MOVEMENTS : INDEX PERFORMANCE
        // ─────────────────────────────────────────────
        // Note : index composé (category, item_name) dépasse la limite InnoDB
        // de 1000 bytes en utf8mb4 (191*4*2 = 1528). On utilise un index
        // avec préfixe tronqué via SQL brut.
        $exists = collect(DB::select(
            "SHOW INDEX FROM stocks WHERE Key_name = 'idx_stocks_category_name'"
        ))->isNotEmpty();

        if (! $exists) {
            DB::statement('ALTER TABLE `stocks` ADD INDEX `idx_stocks_category_name` (`category`(50), `item_name`(100))');
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $exists = collect(DB::select(
                "SHOW INDEX FROM stock_movements WHERE Key_name = 'idx_stock_movements_stock_created'"
            ))->isNotEmpty();

            if (! $exists) {
                $table->index(['stock_id', 'created_at'], 'idx_stock_movements_stock_created');
            }
        });
    }

    public function down(): void
    {
        // Suppression des colonnes ajoutées
        Schema::table('batches', function (Blueprint $table) {
            if (Schema::hasColumn('batches', 'parent_batch_id')) {
                $table->dropForeign(['parent_batch_id']);
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

            $exists = collect(DB::select(
                "SHOW INDEX FROM batch_tasks WHERE Key_name = 'idx_batch_tasks_planned'"
            ))->isNotEmpty();
            if ($exists) {
                $table->dropIndex('idx_batch_tasks_planned');
            }
        });

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
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $indexes) {
                foreach ($indexes as $name) {
                    $exists = collect(DB::select("SHOW INDEX FROM {$tableName} WHERE Key_name = ?", [$name]))->isNotEmpty();
                    if ($exists) {
                        $table->dropIndex($name);
                    }
                }
            });
        }
    }
};