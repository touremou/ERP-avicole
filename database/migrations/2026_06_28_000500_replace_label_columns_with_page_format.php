<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le nombre d'étiquettes par page est désormais AUTOMATIQUE (étiquettes en mm
 * réparties selon le format de page). On remplace donc le réglage « colonnes »
 * par un « format de page » (seule / A4 / A5 / A6).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        DB::table('settings')->where('group', 'etiquettes')->where('key', 'columns')->delete();

        $exists = DB::table('settings')->where('group', 'etiquettes')->where('key', 'page_format')->whereNull('farm_id')->exists();
        if (! $exists) {
            DB::table('settings')->insert([
                'group' => 'etiquettes', 'key' => 'page_format', 'value' => 'a4', 'type' => 'select',
                'label' => 'Format de page', 'options' => 'seule,a4,a5,a6', 'display_order' => 3,
                'description' => 'Disposition automatique : « seule » (1 étiquette) ou feuille A4 / A5 / A6.',
                'is_sensitive' => false, 'farm_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) return;
        DB::table('settings')->where('group', 'etiquettes')->where('key', 'page_format')->delete();
    }
};
