<?php

use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('la page Couvoir répond avec le header modernisé et le statut parc dans le corps', function () {
    $this->actingAs($this->adminUser)
        ->get(route('incubations.index'))
        ->assertOk()
        ->assertSee('Couvoir — Incubation')          // nouveau header hub
        ->assertSee('Nouveau Lancement')             // action principale conservée
        ->assertSee('Unités libres')                 // statut du parc descendu dans le corps
        ->assertSee('href="' . route('productions.index') . '"', false); // ancre retour-hub (layout)
});
