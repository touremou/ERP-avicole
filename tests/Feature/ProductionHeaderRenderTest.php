<?php

use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

// Garde-fou : les écrans egg/milk utilisent le composant partagé <x-page-header>.
// Un render réel attrape toute erreur de props/slot que la compilation Blade
// seule ne détecte pas.

test('le dashboard production œufs se rend avec l\'en-tête standardisé', function () {
    $this->actingAs($this->managerUser)
        ->get(route('egg-productions.index'))
        ->assertOk()
        ->assertSee('Dashboard Production');
});

test('la collecte de lait se rend avec l\'en-tête standardisé (accent cyan)', function () {
    $this->actingAs($this->managerUser)
        ->get(route('milk-productions.index'))
        ->assertOk()
        ->assertSee('Collecte de lait')
        ->assertSee('bg-cyan-600', false)          // identité visuelle du module lait
        ->assertSee("Litres aujourd'hui")          // tuile KPI via <x-stat-tile>
        ->assertSee('rounded-[2.5rem]', false);    // panneau via composant
});

test('les dashboards production se rendent avec en-tête standardisé et accent propre', function () {
    $cases = [
        ['slaughter.dashboard',       'Abattoir',             'bg-rose-600'],
        ['provenderie.dashboard',     'Pilotage Provenderie', 'bg-amber-600'],
        ['cultures.dashboard',        'Production Végétale',  'bg-green-600'],
        ['crop-cycles.index',         'Cycles de Culture',    'bg-green-600'],
        ['production.index',          'Journal de Production','bg-amber-600'],
    ];

    foreach ($cases as [$route, $title, $accentClass]) {
        $this->actingAs($this->adminUser)
            ->get(route($route))
            ->assertOk()
            ->assertSee($title)
            ->assertSee($accentClass, false);
    }
});

test('les écrans cultures migrés vers <x-flash> se rendent sans erreur', function () {
    foreach (['plots.index', 'crop-catalogue.index', 'crop-campaigns.index', 'crop-protocols.index'] as $route) {
        $this->actingAs($this->adminUser)->get(route($route))->assertOk();
    }
});
