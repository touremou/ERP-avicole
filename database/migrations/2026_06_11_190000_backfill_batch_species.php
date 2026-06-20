<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renseigne species_id / production_type_id pour les lots créés avant
 * l'introduction du référentiel multiespèces (2026_06_09). Sans ce
 * rattrapage, ces lots (tous volaille à l'époque) restent invisibles
 * des filtres "Espèce" des rapports financiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('batches', 'species_id') || ! Schema::hasColumn('batches', 'production_type_id')) {
            return;
        }

        $poulet = DB::table('species')->where('slug', 'poulet')->first();
        if (! $poulet) {
            return;
        }

        DB::table('batches')->whereNull('species_id')->update(['species_id' => $poulet->id]);

        $productionTypes = DB::table('production_types')
            ->where('species_id', $poulet->id)
            ->pluck('id', 'slug');

        foreach ($productionTypes as $slug => $id) {
            DB::table('batches')
                ->whereNull('production_type_id')
                ->where('species_id', $poulet->id)
                ->where('type', $slug)
                ->update(['production_type_id' => $id]);
        }
    }

    public function down(): void
    {
        // Irréversible : on ne distingue pas les lots backfillés des lots saisis nativement.
    }
};
