<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le paramètre general.species_enabled (ex: ["poulet"]) était une liste figée,
 * jamais mise à jour, devenue incohérente avec la page Espèces (/admin/species)
 * qui est la véritable source de vérité (Species.is_active). On supprime ce
 * paramètre mort : la page Paramètres > Général affiche désormais la liste des
 * espèces actives en direct depuis la table species.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('settings')->where('key', 'species_enabled')->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('setting_audits')->where('key', 'species_enabled')->delete();
            DB::table('settings')->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
        DB::table('settings')->updateOrInsert(
            ['group' => 'general', 'key' => 'species_enabled', 'farm_id' => null],
            [
                'value'        => '["poulet"]',
                'type'         => 'json',
                'label'        => 'Espèces actives sur ce site',
                'description'  => 'Liste JSON des slugs espèces activés sur cette ferme.',
                'display_order'=> 15,
                'is_sensitive' => false,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]
        );
    }
};
