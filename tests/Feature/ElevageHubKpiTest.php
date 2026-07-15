<?php

use App\Models\Batch;
use App\Models\Building;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * KPI du hub Élevage (correctif pré-MEP) : les comptages excluaient PAS les
 * entités virtuelles — le bâtiment « Zone Fournisseurs Externes » et les lots
 * de traçabilité EXT- (effectif initial nul) gonflaient bâtiments, lots
 * actifs et effectif vivant. Scopes canoniques : Building::physical() et
 * Batch::live() (cf. leurs docblocs : « ne doit jamais y figurer »).
 */

beforeEach(function () {
    $this->setUpRbac();
});

test('les KPI du hub élevage excluent bâtiments et lots virtuels', function () {
    // Réels : 2 bâtiments physiques, 2 lots vivants (300 + 200 sujets).
    $b1 = Building::factory()->create(['name' => 'Bâtiment A']);
    $b2 = Building::factory()->create(['name' => 'Bâtiment B']);
    Batch::factory()->create(['building_id' => $b1->id, 'initial_quantity' => 300, 'current_quantity' => 300, 'qty_alive' => 300]);
    Batch::factory()->create(['building_id' => $b2->id, 'initial_quantity' => 200, 'current_quantity' => 200, 'qty_alive' => 200]);

    // Virtuels : la zone fournisseurs externes + un lot de traçabilité EXT-
    // (aucun animal réel) qui porte pourtant un statut Actif.
    $virtualBuilding = Building::factory()->create(['name' => 'Zone Fournisseurs Externes']);
    Batch::factory()->create([
        'code'             => 'EXT-OEUFS-001',
        'building_id'      => $virtualBuilding->id,
        'initial_quantity' => 0,
        'current_quantity' => 150, // stock d'œufs virtuel : PAS des animaux
        'qty_alive'        => 0,
    ]);

    $response = $this->actingAs($this->managerUser)
        ->get(route('elevage.index'))
        ->assertOk();

    $kpis = $response->viewData('kpis');

    expect($kpis['buildings'])->toBe(2);      // sans la zone virtuelle
    expect($kpis['active_lots'])->toBe(2);    // sans le lot EXT-
    expect($kpis['livestock'])->toBe(500);    // 300 + 200, sans les 150 « œufs »
});
