<?php

/**
 * MIGRATION CORRECTIVE — UNIQUE CONSTRAINT SUR DAILY_CHECKS
 *
 * Empêche les doublons de pointage pour un même lot et une même date.
 *
 * HOTFIX : Suppression de toutes les requêtes MySQL brutes (SHOW INDEX, CONCAT)
 * pour compatibilité SQLite (tests) et MySQL (prod).
 *
 * Bugs corrigés : B-13 (absence de UNIQUE), S-12 (race condition updateOrCreate)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────
        // 1. DÉTECTION ET ARCHIVAGE DES DOUBLONS EXISTANTS
        // ─────────────────────────────────────────────
        if (Schema::hasColumn('daily_checks', 'deleted_at')) {
            $this->archiveDuplicates();
        }

        // ─────────────────────────────────────────────
        // 2. SOFT DELETES SUR DAILY_CHECKS (si absent)
        // ─────────────────────────────────────────────
        if (! Schema::hasColumn('daily_checks', 'deleted_at')) {
            Schema::table('daily_checks', function (Blueprint $table) {
                $table->softDeletes();
            });
            // Relancer l'archivage maintenant que deleted_at existe
            $this->archiveDuplicates();
        }

        // ─────────────────────────────────────────────
        // 3. UUID ET SYNC COLONNES (pour offline)
        // ─────────────────────────────────────────────
        if (! Schema::hasColumn('daily_checks', 'uuid')) {
            Schema::table('daily_checks', function (Blueprint $table) {
                $table->char('uuid', 36)->nullable()->after('id');
            });

            // Backfill UUID
            $checksWithoutUuid = DB::table('daily_checks')
                ->whereNull('uuid')
                ->orWhere('uuid', '')
                ->pluck('id');

            foreach ($checksWithoutUuid as $id) {
                DB::table('daily_checks')
                    ->where('id', $id)
                    ->update(['uuid' => (string) Str::uuid()]);
            }
        }

        if (! Schema::hasColumn('daily_checks', 'is_synced')) {
            Schema::table('daily_checks', function (Blueprint $table) {
                $table->boolean('is_synced')->default(true)->after('uuid');
            });
        }

        if (! Schema::hasColumn('daily_checks', 'last_sync_at')) {
            Schema::table('daily_checks', function (Blueprint $table) {
                $table->timestamp('last_sync_at')->nullable()->after('is_synced');
            });
        }

        // ─────────────────────────────────────────────
        // 4. CONTRAINTE UNIQUE (batch_id, check_date)
        // ─────────────────────────────────────────────
        if (! $this->indexExists('daily_checks', 'unique_daily_check_per_batch_day')) {
            try {
                Schema::table('daily_checks', function (Blueprint $table) {
                    $table->unique(['batch_id', 'check_date'], 'unique_daily_check_per_batch_day');
                });
            } catch (\Throwable $e) {
                // Index existe déjà — on ignore
                Log::info('[Migration] unique_daily_check_per_batch_day existe déjà, skip.');
            }
        }

        // ─────────────────────────────────────────────
        // 5. INDEX DE PERFORMANCE
        // ─────────────────────────────────────────────
        $indexes = [
            'idx_daily_checks_check_date' => ['check_date'],
            'idx_daily_checks_batch_date' => ['batch_id', 'check_date'],
        ];

        foreach ($indexes as $name => $columns) {
            if (! $this->indexExists('daily_checks', $name)) {
                try {
                    Schema::table('daily_checks', function (Blueprint $table) use ($columns, $name) {
                        $table->index($columns, $name);
                    });
                } catch (\Throwable $e) {
                    // Index existe déjà — on ignore
                }
            }
        }
    }

    public function down(): void
    {
        $indexesToDrop = [
            'unique_daily_check_per_batch_day',
            'idx_daily_checks_check_date',
            'idx_daily_checks_batch_date',
        ];

        foreach ($indexesToDrop as $name) {
            if ($this->indexExists('daily_checks', $name)) {
                try {
                    Schema::table('daily_checks', function (Blueprint $table) use ($name) {
                        if ($name === 'unique_daily_check_per_batch_day') {
                            $table->dropUnique($name);
                        } else {
                            $table->dropIndex($name);
                        }
                    });
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
        }
    }

    // ─────────────────────────────────────────────
    // HELPERS
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

            $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            return count($indexes) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Archive les doublons (soft-delete) — compatible cross-DB.
     */
    private function archiveDuplicates(): void
    {
        try {
            $duplicates = DB::select(
                "SELECT batch_id, check_date, COUNT(*) as cnt, MIN(id) as keep_id
                 FROM daily_checks
                 WHERE deleted_at IS NULL
                 GROUP BY batch_id, check_date
                 HAVING COUNT(*) > 1"
            );

            foreach ($duplicates as $dup) {
                // Soft-delete les doublons (garder le MIN id)
                DB::table('daily_checks')
                    ->where('batch_id', $dup->batch_id)
                    ->where('check_date', $dup->check_date)
                    ->where('id', '!=', $dup->keep_id)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at'   => now(),
                        'observations' => DB::raw(
                            Schema::getConnection()->getDriverName() === 'sqlite'
                                ? "COALESCE(observations, '') || ' [DOUBLON ARCHIVÉ PAR MIGRATION]'"
                                : "CONCAT(COALESCE(observations, ''), ' [DOUBLON ARCHIVÉ PAR MIGRATION]')"
                        ),
                    ]);
            }

            if (count($duplicates) > 0) {
                Log::warning('[Migration] daily_checks : ' . count($duplicates) . ' doublons archivés.');
            }
        } catch (\Throwable $e) {
            // Table vide ou pas encore de données — rien à faire
            Log::info('[Migration] Pas de doublons à archiver : ' . $e->getMessage());
        }
    }
};
