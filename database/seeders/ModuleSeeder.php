<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;

class ModuleSeeder extends Seeder
{
    /**
     * SOURCE DE VÉRITÉ des modules ERP — DOIT rester alignée avec la
     * migration 2026_06_04_000001_create_module_rbac_tables (mêmes slugs,
     * icônes, couleurs et ordre) + le module « Dépenses » (migration
     * 2026_06_11_110001).
     *
     * Historique : une ancienne version de ce seeder créait des slugs
     * legacy (rh, couvoir, stocks) DIFFÉRENTS de ceux de la migration,
     * ce qui insérait des modules DOUBLONS (les gates du code visent
     * annuaire/logistique/production). La migration de consolidation
     * 2026_06_14_000002 nettoie ces doublons. Ne JAMAIS réintroduire de
     * slug ici qui ne soit pas un slug canonique.
     */
    public function run(): void
    {
        $modules = [
            ['name' => 'Dashboard',      'slug' => 'dashboard',     'icon' => 'fa-gauge-high',      'color' => 'slate',   'display_order' => 0],
            ['name' => 'Élevage',        'slug' => 'elevage',       'icon' => 'fa-dove',            'color' => 'blue',    'display_order' => 1],
            ['name' => 'Production',     'slug' => 'production',    'icon' => 'fa-egg',             'color' => 'amber',   'display_order' => 2],
            ['name' => 'Provenderie',    'slug' => 'provenderie',   'icon' => 'fa-wheat-awn',       'color' => 'lime',    'display_order' => 3],
            ['name' => 'Planning',       'slug' => 'planning',      'icon' => 'fa-calendar-days',   'color' => 'indigo',  'display_order' => 4],
            ['name' => 'Abattoir',       'slug' => 'abattoir',      'icon' => 'fa-drumstick-bite',  'color' => 'rose',    'display_order' => 5],
            ['name' => 'Commerce',       'slug' => 'commerce',      'icon' => 'fa-cash-register',   'color' => 'teal',    'display_order' => 6],
            ['name' => 'Logistique',     'slug' => 'logistique',    'icon' => 'fa-truck',           'color' => 'orange',  'display_order' => 7],
            ['name' => 'Ressources',     'slug' => 'ressources',    'icon' => 'fa-bolt',            'color' => 'cyan',    'display_order' => 8],
            ['name' => 'Notifications',  'slug' => 'notifications', 'icon' => 'fa-bell',            'color' => 'emerald', 'display_order' => 9],
            ['name' => 'Annuaire',       'slug' => 'annuaire',      'icon' => 'fa-users',           'color' => 'slate',   'display_order' => 10],
            ['name' => 'Administration', 'slug' => 'admin',         'icon' => 'fa-shield-halved',   'color' => 'purple',  'display_order' => 11],
            ['name' => 'Dépenses',       'slug' => 'depenses',      'icon' => 'fa-receipt',         'color' => 'rose',    'display_order' => 12],
            ['name' => 'Production Végétale', 'slug' => 'cultures', 'icon' => 'fa-seedling',        'color' => 'green',   'display_order' => 13],
        ];

        foreach ($modules as $mod) {
            Module::updateOrCreate(
                ['slug' => $mod['slug']],
                [
                    'name'          => $mod['name'],
                    'icon'          => $mod['icon'],
                    'color'         => $mod['color'],
                    'display_order' => $mod['display_order'],
                    'is_active'     => true,
                ]
            );
        }
    }
}