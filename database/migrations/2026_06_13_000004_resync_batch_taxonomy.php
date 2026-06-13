<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resynchronise `type` (slug legacy) et `species_id` des lots avec leur
 * type de production rattaché, qui fait désormais foi (cf.
 * Batch::syncTaxonomyFromProductionType). Répare les lots créés avant
 * l'introduction de cet invariant, dont les trois champs pouvaient diverger
 * (ex. production_type « ponte » mais type='chair').
 *
 * Idempotent : ne touche que les lignes effectivement incohérentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('batches') || ! Schema::hasTable('production_types')) {
            return;
        }

        if (! Schema::hasColumn('batches', 'production_type_id') || ! Schema::hasColumn('batches', 'species_id')) {
            return;
        }

        $productionTypes = DB::table('production_types')->get(['id', 'slug', 'species_id']);

        foreach ($productionTypes as $pt) {
            DB::table('batches')
                ->where('production_type_id', $pt->id)
                ->where(function ($q) use ($pt) {
                    $q->where('type', '!=', $pt->slug)
                        ->orWhereNull('type')
                        ->orWhere('species_id', '!=', $pt->species_id)
                        ->orWhereNull('species_id');
                })
                ->update([
                    'type'       => $pt->slug,
                    'species_id' => $pt->species_id,
                ]);
        }
    }

    public function down(): void
    {
        // Irréversible : on ne conserve pas les valeurs incohérentes d'origine.
    }
};
