<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Préfixes de numérotation de la LOGISTIQUE INTER-SITES (expédition, réception).
 *
 * Ces deux documents numérotaient à la main dans leurs actions, avec un préfixe
 * codé en dur. En les ramenant sous DocumentNumberingService, ils héritent du
 * verrou de séquence et de la garde d'unicité — et, comme tous les autres, leur
 * préfixe devient configurable. Sans cette ligne de réglage, il le serait « en
 * théorie » : lu par le service, réglable nulle part.
 *
 * Les valeurs par défaut reprennent EXACTEMENT le format existant (EXP-/REC- +
 * année + 6 chiffres) : les séquences en cours continuent sans rupture.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        $now = now();

        $settings = [
            ['group' => 'numbering', 'key' => 'dispatch_prefix',  'value' => 'EXP', 'type' => 'string', 'label' => 'Préfixe expédition inter-sites', 'display_order' => 11, 'description' => 'Format : EXP-2026-000001'],
            ['group' => 'numbering', 'key' => 'reception_prefix', 'value' => 'REC', 'type' => 'string', 'label' => 'Préfixe réception inter-sites',  'display_order' => 12, 'description' => 'Format : REC-2026-000001'],
        ];

        foreach ($settings as $s) {
            $exists = DB::table('settings')
                ->where('group', $s['group'])
                ->where('key', $s['key'])
                ->whereNull('farm_id')
                ->exists();

            if (! $exists) {
                DB::table('settings')->insert(array_merge([
                    'options'      => null,
                    'description'  => null,
                    'is_sensitive' => false,
                    'farm_id'      => null,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ], $s));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) return;

        DB::table('settings')->where('group', 'numbering')
            ->whereIn('key', ['dispatch_prefix', 'reception_prefix'])
            ->delete();
    }
};
