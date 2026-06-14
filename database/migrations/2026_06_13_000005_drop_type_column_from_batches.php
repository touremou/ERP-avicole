<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression physique de `batches.type` (slug legacy : chair, ponte,
 * poussiniere, reproducteur, laitiere, engraissement...).
 *
 * `production_type_id` (cf. 2026_06_09_100003 + invariant
 * Batch::syncTaxonomyFromProductionType, 2026_06_13_000004) est désormais
 * l'unique source de vérité ; `type` devient un accessor calculé
 * (Batch::getTypeAttribute = productionType->slug).
 *
 * Avant de supprimer la colonne, on garantit que CHAQUE lot dispose d'un
 * production_type_id : pour les lignes encore orphelines (lots créés avant
 * 2026_06_09 dont le `type` ne correspondait à aucun production_types.slug
 * de leur espèce — ex. 'engraissement'/'laitiere' sur poulet), on
 * retrouve/crée le ProductionType (espèce, slug) correspondant et on
 * l'attache.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('batches') || ! Schema::hasColumn('batches', 'type')) {
            return;
        }

        if (Schema::hasTable('production_types') && Schema::hasTable('species')) {
            $defaultSpeciesId = DB::table('species')->where('slug', 'poulet')->value('id');

            $orphans = DB::table('batches')
                ->whereNull('production_type_id')
                ->get(['id', 'type', 'species_id']);

            foreach ($orphans as $batch) {
                $speciesId = $batch->species_id ?? $defaultSpeciesId;

                if (! $speciesId) {
                    continue;
                }

                $slug = $batch->type ?: 'chair';

                $productionTypeId = DB::table('production_types')
                    ->where('species_id', $speciesId)
                    ->where('slug', $slug)
                    ->value('id');

                if (! $productionTypeId) {
                    $productionTypeId = DB::table('production_types')->insertGetId([
                        'species_id' => $speciesId,
                        'slug' => $slug,
                        'name_fr' => ucfirst($slug),
                        'metrics_enabled' => json_encode([
                            'mortality' => true, 'feed' => true, 'weight' => true,
                            'eggs' => false, 'milk' => false, 'water_quality' => false,
                            'born' => false, 'weaned' => false,
                        ]),
                        'kpi_primary' => 'fcr',
                        'cycle_days_default' => 45,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('batches')->where('id', $batch->id)->update([
                    'production_type_id' => $productionTypeId,
                    'species_id' => $speciesId,
                ]);
            }
        }

        // L'index composite idx_batches_status_type porte sur (status, type) :
        // il doit être supprimé avant la colonne, sinon SQLite refuse le DROP.
        if ($this->indexExists('batches', 'idx_batches_status_type')) {
            Schema::table('batches', function (Blueprint $table) {
                $table->dropIndex('idx_batches_status_type');
            });
        }

        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('type');
            $table->index('status', 'idx_batches_status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('batches') || Schema::hasColumn('batches', 'type')) {
            return;
        }

        if ($this->indexExists('batches', 'idx_batches_status')) {
            Schema::table('batches', function (Blueprint $table) {
                $table->dropIndex('idx_batches_status');
            });
        }

        // Irréversible : on ne reconstitue pas les valeurs d'origine, on
        // recrée seulement la colonne (nullable) pour compat de rollback.
        Schema::table('batches', function (Blueprint $table) {
            $table->string('type', 50)->nullable()->after('species_id');
            $table->index(['status', 'type'], 'idx_batches_status_type');
        });
    }

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
};
