<?php

use App\Models\EnergyReading;
use App\Models\EnergySource;
use App\Models\WaterSource;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Connexion RESSOURCES → bandeau d'alertes priorisé du dashboard principal
 * (revue module utilities pré-MEP) : citernes d'eau basses, gasoil des
 * groupes électrogènes, maintenance préventive due — le centre de contrôle
 * unifié voit les utilités sans ouvrir le module Ressources.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Closure liée au TestCase (managerUser est protected — pas de fonction globale).
    $this->alertTitles = function (): Illuminate\Support\Collection {
        $response = $this->actingAs($this->managerUser)->get(route('dashboard'))->assertOk();

        return collect($response->viewData('priorityAlerts'))->pluck('title');
    };
});

test('citerne d\'eau : sous 15 % l\'alerte escalade au bandeau (le bandeau est critique-only)', function () {
    WaterSource::create([
        'name' => 'Citerne principale', 'type' => 'citerne',
        'capacity_liters' => 10000, 'current_level_liters' => 2000,
        'current_level_percent' => 20, 'is_active' => true,
    ]);

    // 20 % : bas, mais géré au module Ressources — le bandeau hub n'escalade
    // que les urgences critiques (philosophie existante du centre de contrôle).
    expect(($this->alertTitles)())->not->toContain('Citerne d\'eau critique');

    // Sous 15 % → escalade au bandeau.
    WaterSource::query()->update(['current_level_percent' => 10, 'current_level_liters' => 1000]);

    $alerts = collect($this->actingAs($this->managerUser)->get(route('dashboard'))
        ->assertOk()->viewData('priorityAlerts'));
    $alert = $alerts->firstWhere('title', 'Citerne d\'eau critique');

    expect($alert)->not->toBeNull();
    expect($alert['detail'])->toContain('Citerne principale');
});

test('gasoil de groupe critique : alerte au dashboard avec autonomie estimée', function () {
    $groupe = EnergySource::create([
        'name' => 'Groupe 40 kVA', 'type' => 'groupe', 'fuel_type' => 'diesel',
        'fuel_tank_capacity' => 200, 'current_fuel_level' => 10,
        'maintenance_interval_hours' => 250, 'total_hours_run' => 10,
        'is_active' => true, 'status' => 'operationnel',
    ]);

    // Régime récent : 1 L/h → autonomie ≈ 10 h ≤ seuil 24 h → critique.
    EnergyReading::create([
        'energy_source_id' => $groupe->id, 'reading_date' => now()->subDay()->toDateString(),
        'hours_run' => 5, 'fuel_consumed_liters' => 5, 'user_id' => $this->managerUser->id,
    ]);

    expect(($this->alertTitles)())->toContain('Gasoil groupe critique');
});

test('maintenance de groupe due : alerte au dashboard (heures restantes)', function () {
    EnergySource::create([
        'name' => 'Groupe secours', 'type' => 'groupe', 'fuel_type' => 'diesel',
        'maintenance_interval_hours' => 100, 'total_hours_run' => 90, // 10 h restantes ≤ 20
        'is_active' => true, 'status' => 'operationnel',
    ]);

    expect(($this->alertTitles)())->toContain('Maintenance groupe due');
});

test('ressources saines : aucune alerte utilités au dashboard', function () {
    WaterSource::create([
        'name' => 'Citerne pleine', 'type' => 'citerne',
        'capacity_liters' => 10000, 'current_level_liters' => 9000,
        'current_level_percent' => 90, 'is_active' => true,
    ]);
    EnergySource::create([
        'name' => 'Groupe neuf', 'type' => 'groupe', 'fuel_type' => 'diesel',
        'fuel_tank_capacity' => 200, 'current_fuel_level' => 180,
        'maintenance_interval_hours' => 250, 'total_hours_run' => 10,
        'is_active' => true, 'status' => 'operationnel',
    ]);

    $titles = ($this->alertTitles)();
    expect($titles)->not->toContain('Citerne d\'eau critique');
    expect($titles)->not->toContain('Gasoil groupe critique');
    expect($titles)->not->toContain('Maintenance groupe due');
});
