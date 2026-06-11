<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contraintes d'intégrité « industrielles ».
 *
 * egg_productions (batch_id, production_date) : la collecte est cumulée dans
 * UNE seule ligne par lot et par jour (RecordEggCollection::updateOrCreate).
 * Un index existait mais n'était pas UNIQUE : deux ramassages concurrents
 * pouvaient passer le test d'existence et créer deux lignes (double comptage).
 * On rend l'unicité explicite — la base devient le garde-fou anti-doublon.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('egg_productions')) {
            return;
        }

        // On ne crée la contrainte que si aucun doublon ne subsiste.
        $hasDuplicates = DB::table('egg_productions')
            ->select('batch_id', 'production_date')
            ->groupBy('batch_id', 'production_date')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            return;
        }

        if (! $this->indexExists('egg_productions', 'egg_productions_batch_date_unique')) {
            Schema::table('egg_productions', function (Blueprint $table) {
                $table->unique(['batch_id', 'production_date'], 'egg_productions_batch_date_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('egg_productions', 'egg_productions_batch_date_unique')) {
            Schema::table('egg_productions', function (Blueprint $table) {
                $table->dropUnique('egg_productions_batch_date_unique');
            });
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
