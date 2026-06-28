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
        ->assertSee('bg-cyan-600', false); // identité visuelle du module lait
});
