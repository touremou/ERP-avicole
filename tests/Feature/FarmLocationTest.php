<?php

use App\Models\Farm;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('la page Sites se rend avec le bouton d\'édition', function () {
    $this->actingAs($this->adminUser)
        ->get(route('farms.index'))
        ->assertOk()
        ->assertSee('Éditer')
        ->assertSee('editFarmModal', false);
});

test('un admin peut renseigner la ville/région d\'une ferme existante', function () {
    $farm = Farm::create(['name' => 'Ferme Sans Ville', 'code' => 'F-SV', 'is_active' => true]);

    $this->actingAs($this->adminUser)
        ->from(route('farms.index'))
        ->put(route('farms.update', $farm), [
            'name'   => 'Ferme Sans Ville',
            'city'   => 'Dubréka',
            'region' => 'Kindia',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $farm->refresh();
    expect($farm->city)->toBe('Dubréka');
    expect($farm->region)->toBe('Kindia');
});

test('changer la ville invalide le géocodage mémorisé et les caches météo', function () {
    $farm = Farm::create([
        'name'     => 'Ferme', 'code' => 'F-GEO', 'city' => 'Conakry', 'is_active' => true,
        'settings' => ['geo' => ['query' => 'Conakry', 'lat' => 9.5, 'lon' => -13.6, 'label' => 'Conakry']],
    ]);

    // Caches météo « chauds » pour cette ferme.
    Cache::put("weather.current.farm.{$farm->id}", ['temp_max' => 30], now()->addHour());
    Cache::put("weather.forecast.farm.{$farm->id}.3", [['x' => 1]], now()->addHours(3));

    $this->actingAs($this->adminUser)
        ->put(route('farms.update', $farm), [
            'name' => 'Ferme',
            'city' => 'Labé', // ville changée
        ])
        ->assertRedirect();

    $farm->refresh();
    expect($farm->getSetting('geo'))->toBeNull();              // géocodage purgé
    expect(Cache::has("weather.current.farm.{$farm->id}"))->toBeFalse();
    expect(Cache::has("weather.forecast.farm.{$farm->id}.3"))->toBeFalse();
});
