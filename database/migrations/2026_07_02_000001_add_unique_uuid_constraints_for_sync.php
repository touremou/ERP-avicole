<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotence de la synchro offline — dernière ligne de défense EN BASE.
 *
 * Les uuid générés côté terrain (prepare_tables_for_offline_sync) étaient
 * indexés mais PAS uniques sur daily_checks / health_checks / incubations /
 * egg_productions : le contrôle d'existence applicatif (SyncController)
 * suffisait en série, mais deux replays réseau strictement concurrents
 * pouvaient passer le `where('uuid')->first()` tous les deux et créer un
 * doublon. Audit 360° §1.2-B2 : toute idempotence applicative doit être
 * doublée d'un index UNIQUE (pattern déjà appliqué à batches, sales,
 * expenses, stock_movements et aux tables cultures).
 *
 * Prudence prod : la contrainte n'est posée que si aucun doublon non-null
 * n'existe (même garde que 2026_06_11_170000) — le test de structure
 * DatabaseConstraintGuardTest échoue si elle manque sur une base saine.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        'daily_checks',
        'health_checks',
        'incubations',
        'egg_productions',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uuid')) {
                continue;
            }

            $hasDuplicates = DB::table($table)
                ->whereNotNull('uuid')
                ->select('uuid')
                ->groupBy('uuid')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($hasDuplicates) {
                continue; // à assainir manuellement avant de rejouer la migration
            }

            $index = "{$table}_uuid_unique";

            if (! $this->indexExists($table, $index)) {
                Schema::table($table, function (Blueprint $t) use ($index) {
                    $t->unique('uuid', $index);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            $index = "{$table}_uuid_unique";

            if (Schema::hasTable($table) && $this->indexExists($table, $index)) {
                Schema::table($table, function (Blueprint $t) use ($index) {
                    $t->dropUnique($index);
                });
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list({$table})"))
                ->contains(fn ($i) => $i->name === $index);
        }

        // MySQL / MariaDB
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
