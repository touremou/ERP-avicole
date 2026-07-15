<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètres d'impression des étiquettes / QR (groupe « etiquettes ») :
 * impression auto, nombre de copies, colonnes par page, et infos affichées.
 * Rend le rendu des étiquettes configurable sans redéploiement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) return;

        $now = now();
        $settings = [
            ['key' => 'autoprint',       'value' => '0', 'type' => 'boolean', 'label' => 'Ouvrir directement la boîte d\'impression', 'display_order' => 1, 'description' => 'Si activé, l\'étiquette lance l\'impression automatiquement (sinon aperçu).'],
            ['key' => 'copies',          'value' => '1', 'type' => 'integer', 'label' => 'Nombre de copies par défaut',                'display_order' => 2, 'description' => 'Nombre d\'exemplaires de l\'étiquette à imprimer.'],
            ['key' => 'columns',         'value' => '2', 'type' => 'integer', 'label' => 'Étiquettes par ligne (page imprimable)',     'display_order' => 3, 'description' => 'Disposition en grille : nombre d\'étiquettes côte à côte.'],
            ['key' => 'show_farm',       'value' => '1', 'type' => 'boolean', 'label' => 'Afficher le nom de la ferme',                'display_order' => 4, 'description' => null],
            ['key' => 'show_caption',    'value' => '1', 'type' => 'boolean', 'label' => 'Afficher la mention « Scanner pour la traçabilité »', 'display_order' => 5, 'description' => null],
            ['key' => 'show_printed_at', 'value' => '0', 'type' => 'boolean', 'label' => 'Afficher la date d\'impression',             'display_order' => 6, 'description' => null],
        ];

        foreach ($settings as $s) {
            $exists = DB::table('settings')->where('group', 'etiquettes')->where('key', $s['key'])->whereNull('farm_id')->exists();
            if ($exists) continue;

            DB::table('settings')->insert(array_merge([
                'group' => 'etiquettes', 'options' => null, 'is_sensitive' => false,
                'farm_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ], $s));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) return;
        DB::table('settings')->where('group', 'etiquettes')->delete();
    }
};
