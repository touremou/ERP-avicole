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
        // ORDRE INDUSTRIEL du lanceur (display_order) : cœur élevage → aval
        // (abattoir) → commercial/finance → support → végétal en dernier module
        // métier, admin en clôture. Planning & Notifications ne sont PAS des tuiles
        // du lanceur (display_order 90+) : intégrés ailleurs (Planning → hub Élevage ;
        // Notifications → cloche + menu utilisateur), cf. Module::nonLauncherSlugs().
        // Leurs modules/permissions restent intacts.
        $modules = [
            ['name' => 'Dashboard',      'slug' => 'dashboard',     'icon' => 'fa-gauge-high',      'color' => 'slate',   'display_order' => 0],
            ['name' => 'Élevage',        'slug' => 'elevage',       'icon' => 'fa-dove',            'color' => 'blue',    'display_order' => 1],
            ['name' => 'Production',     'slug' => 'production',    'icon' => 'fa-egg',             'color' => 'amber',   'display_order' => 2],
            ['name' => 'Provenderie',    'slug' => 'provenderie',   'icon' => 'fa-wheat-awn',       'color' => 'lime',    'display_order' => 3],
            ['name' => 'Abattoir',       'slug' => 'abattoir',      'icon' => 'fa-drumstick-bite',  'color' => 'rose',    'display_order' => 4],
            ['name' => 'Commerce / Ventes', 'slug' => 'commerce',   'icon' => 'fa-file-invoice-dollar', 'color' => 'teal', 'display_order' => 5],
            ['name' => 'Caisse / POS',   'slug' => 'caisse',        'icon' => 'fa-cash-register',   'color' => 'teal',    'display_order' => 6],
            ['name' => 'Finance',        'slug' => 'depenses',      'icon' => 'fa-coins',           'color' => 'rose',    'display_order' => 7],
            ['name' => 'Logistique',     'slug' => 'logistique',    'icon' => 'fa-truck',           'color' => 'orange',  'display_order' => 7],
            ['name' => 'Eau & Énergie',  'slug' => 'ressources',    'icon' => 'fa-bolt',            'color' => 'cyan',    'display_order' => 8],
            ['name' => 'Annuaire / Tiers', 'slug' => 'annuaire',    'icon' => 'fa-address-book',     'color' => 'slate',   'display_order' => 9],
            ['name' => 'Ressources Humaines', 'slug' => 'rh',       'icon' => 'fa-user-tie',         'color' => 'violet',  'display_order' => 10],
            ['name' => 'Production Végétale', 'slug' => 'cultures', 'icon' => 'fa-seedling',        'color' => 'green',   'display_order' => 11],
            ['name' => 'Administration', 'slug' => 'admin',         'icon' => 'fa-shield-halved',   'color' => 'purple',  'display_order' => 12],

            // Hors lanceur (intégrés ailleurs) :
            ['name' => 'Planning',       'slug' => 'planning',      'icon' => 'fa-calendar-days',   'color' => 'indigo',  'display_order' => 90],
            ['name' => 'Notifications',  'slug' => 'notifications', 'icon' => 'fa-bell',            'color' => 'emerald', 'display_order' => 91],
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