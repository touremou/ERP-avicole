<?php

use Illuminate\Support\Facades\Route;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('le référentiel des normes est rattaché à l\'élevage, pas à l\'admin', function () {
    // L'ancienne route admin.norms.* n'existe plus...
    expect(Route::has('admin.norms.index'))->toBeFalse()
        // ...remplacée par une route élevage (fil d'Ariane « Lots › Normes »).
        ->and(Route::has('batches.norms.index'))->toBeTrue()
        ->and(route('batches.norms.index', absolute: false))->toContain('/batches/norms');
});

test('la page des normes s\'affiche sous le contexte élevage', function () {
    $this->actingAs($this->adminUser)
        ->get(route('batches.norms.index'))
        ->assertOk()
        ->assertSee('Référentiel des Normes');
});

test('le retour du registre des suivis quotidiens cible la liste des lots', function () {
    // L'ancre de retour (x-hub-back) du registre des suivis doit remonter vers
    // les LOTS (batches.index), pas vers le hub Élevage perçu comme dashboard.
    $this->actingAs($this->adminUser)
        ->get(route('daily-checks.index'))
        ->assertOk()
        ->assertSee(route('batches.index'));
});
