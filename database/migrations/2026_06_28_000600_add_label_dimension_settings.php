<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensions d'étiquette configurables (standards ERP / planches Avery, ou
 * format personnalisé). La largeur pilote le nombre d'étiquettes par ligne ;
 * combinée au format de page, le nombre par page reste automatique.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        $now = now();
        $rows = [
            ['key' => 'label_preset', 'value' => '90x50', 'type' => 'select',
             'label' => 'Gabarit d\'étiquette', 'options' => '90x50,100x50,105x148,70x37,63.5x38,38x21,custom',
             'display_order' => 7, 'description' => 'Standards (l×h mm) : 90×50, 100×50, 105×148 (A6), 70×37 (A4 ×24), 63.5×38 (A4 ×21), 38×21, ou « custom ».'],
            ['key' => 'label_width',  'value' => '90', 'type' => 'integer',
             'label' => 'Largeur (mm) — gabarit personnalisé', 'display_order' => 8, 'description' => 'Utilisée si gabarit = custom.'],
            ['key' => 'label_height', 'value' => '50', 'type' => 'integer',
             'label' => 'Hauteur (mm) — gabarit personnalisé (0 = auto)', 'display_order' => 9, 'description' => 'Utilisée si gabarit = custom. 0 = hauteur ajustée au contenu.'],
            ['key' => 'label_gap',    'value' => '4', 'type' => 'integer',
             'label' => 'Espacement entre étiquettes (mm)', 'display_order' => 10, 'description' => null],
        ];

        foreach ($rows as $r) {
            $exists = DB::table('settings')->where('group', 'etiquettes')->where('key', $r['key'])->whereNull('farm_id')->exists();
            if ($exists) continue;
            DB::table('settings')->insert(array_merge([
                'group' => 'etiquettes', 'options' => null, 'is_sensitive' => false,
                'farm_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ], $r));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) return;
        DB::table('settings')->where('group', 'etiquettes')
            ->whereIn('key', ['label_preset', 'label_width', 'label_height', 'label_gap'])->delete();
    }
};
