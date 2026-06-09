<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Module;
use Illuminate\Support\Str; // 👈 N'oubliez pas d'ajouter cette ligne !

class ModuleSeeder extends Seeder
{
    public function run()
    {
        $modules = [
            ['name' => 'Dashboard',   'icon' => 'fa-gauge-high',       'color' => 'blue'],
            ['name' => 'RH',          'icon' => 'fa-users',            'color' => 'slate'],
            ['name' => 'Élevage',     'icon' => 'fa-dove',             'color' => 'blue'],
            ['name' => 'Production',  'icon' => 'fa-egg',              'color' => 'amber'],
            ['name' => 'Couvoir',     'icon' => 'fa-temperature-half', 'color' => 'pink'],
            ['name' => 'Provenderie', 'icon' => 'fa-wheat-awn',        'color' => 'lime'],
            ['name' => 'Planning',    'icon' => 'fa-calendar-days',    'color' => 'indigo'],
            ['name' => 'Abattoir',    'icon' => 'fa-drumstick-bite',   'color' => 'rose'],
            ['name' => 'Commerce',    'icon' => 'fa-cash-register',    'color' => 'teal'],
            ['name' => 'Stocks',      'icon' => 'fa-boxes-stacked',    'color' => 'orange'],
            ['name' => 'Ressources',  'icon' => 'fa-bolt',             'color' => 'cyan'],
            ['name' => 'Admin',       'icon' => 'fa-shield-halved',    'color' => 'purple'],
        ];

        foreach ($modules as $mod) {
            $slug = Str::slug($mod['name']); // On génère le slug d'abord

            Module::updateOrCreate(
                ['slug' => $slug], // 👈 On cherche le module par son slug (unique)
                [
                    'name'  => $mod['name'], // On met à jour le nom (au cas où il a changé)
                    'icon'  => $mod['icon'],
                    'color' => $mod['color']
                ]
            );
        }
    }
}