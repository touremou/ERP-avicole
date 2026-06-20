<?php

use App\Models\CropCycle;
use App\Models\CropTransformation;
use App\Models\Harvest;
use App\Models\Plot;
use Database\Seeders\CultureDemoSeeder;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

test('le seeder de démo crée un jeu de données cohérent', function () {
    $this->seed(CultureDemoSeeder::class);

    expect(Plot::count())->toBe(3)
        ->and(CropCycle::count())->toBe(3)
        ->and(Harvest::count())->toBe(5)
        ->and(CropTransformation::count())->toBe(1);
});

test('le seeder de démo est idempotent (relançable sans doublon)', function () {
    $this->seed(CultureDemoSeeder::class);
    $this->seed(CultureDemoSeeder::class);

    expect(Plot::count())->toBe(3)
        ->and(CropCycle::count())->toBe(3)
        ->and(Harvest::count())->toBe(5)
        ->and(\App\Models\CropInput::count())->toBe(4)
        ->and(CropTransformation::count())->toBe(1);
});
