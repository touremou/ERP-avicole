<?php

use App\Models\AssetMaintenanceLog;
use App\Models\EnergySource;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function healthyGroup(int $farmId): EnergySource
{
    return EnergySource::create([
        'farm_id' => $farmId, 'name' => 'Groupe A', 'type' => 'groupe',
        'depreciation_years' => 5, 'total_hours_run' => 0, 'maintenance_interval_hours' => 250,
        'status' => 'operationnel', 'is_active' => true,
    ]);
}

test('le formulaire de maintenance est accessible sur un groupe sain (préventif)', function () {
    $g = healthyGroup($this->farm->id);
    expect($g->needs_maintenance)->toBeFalse(); // garde-fou : le groupe est bien sain

    $this->actingAs($this->adminUser)->get(route('utilities.energy.sources'))
        ->assertOk()
        ->assertSee(route('utilities.energy.maintenance', $g), false) // l'action du formulaire est rendue
        ->assertSee('préventive ou corrective');                     // libellé du mode préventif
});

test('l\'historique de maintenance affiche un état vide quand il n\'y a aucune intervention', function () {
    $g = healthyGroup($this->farm->id);

    $this->actingAs($this->adminUser)->get(route('utilities.energy.logs', $g))
        ->assertOk()
        ->assertSee('Aucune intervention enregistrée');
});

test('enregistrer une maintenance crée un log et l\'historique l\'affiche', function () {
    $g = healthyGroup($this->farm->id);

    $this->actingAs($this->adminUser)->put(route('utilities.energy.maintenance', $g), [
        'maintenance_type' => 'vidange', 'cost' => 500000, 'technician' => 'SOGEA',
    ])->assertSessionHasNoErrors();

    expect(AssetMaintenanceLog::where('energy_source_id', $g->id)->count())->toBe(1);

    $this->actingAs($this->adminUser)->get(route('utilities.energy.logs', $g))
        ->assertOk()
        ->assertSee('SOGEA');
});
