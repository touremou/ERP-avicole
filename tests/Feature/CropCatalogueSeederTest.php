<?php

use App\Models\CropSpecies;
use Database\Seeders\CropCatalogueSeeder;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

test('le seeder de catalogue crée les cultures de référence guinéennes', function () {
    $this->seed(CropCatalogueSeeder::class);

    expect(CropSpecies::count())->toBeGreaterThanOrEqual(30);

    // Cultures emblématiques de Guinée présentes.
    foreach (['Riz', 'Fonio', 'Manioc', 'Ananas', 'Mangue', 'Café'] as $name) {
        expect(CropSpecies::where('name', $name)->exists())->toBeTrue();
    }
});

test('le seeder de catalogue renseigne les durées de cycle et rendements', function () {
    $this->seed(CropCatalogueSeeder::class);

    $mais = CropSpecies::where('name', 'Maïs')->first();
    expect($mais)->not->toBeNull()
        ->and($mais->cycle_days_min)->toBe(90)
        ->and($mais->cycle_days_max)->toBe(120)
        ->and((float) $mais->avg_yield_tha)->toBe(4.0)
        ->and($mais->varieties()->count())->toBeGreaterThanOrEqual(2);
});

test('le seeder de catalogue est idempotent', function () {
    $this->seed(CropCatalogueSeeder::class);
    $countAfterFirst = CropSpecies::count();
    $varietiesAfterFirst = CropSpecies::where('name', 'Riz')->first()->varieties()->count();

    $this->seed(CropCatalogueSeeder::class);

    expect(CropSpecies::count())->toBe($countAfterFirst)
        ->and(CropSpecies::where('name', 'Riz')->first()->varieties()->count())->toBe($varietiesAfterFirst);
});
