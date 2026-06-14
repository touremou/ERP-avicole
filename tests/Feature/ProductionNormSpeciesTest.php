<?php

use App\Models\ProductionNorm;
use App\Models\Species;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guessSpeciesSlug rattache chaque souche à la bonne espèce', function () {
    expect(ProductionNorm::guessSpeciesSlug('ISA Brown'))->toBe('poulet')
        ->and(ProductionNorm::guessSpeciesSlug('Poule Pondeuse (ISA)'))->toBe('poulet')
        ->and(ProductionNorm::guessSpeciesSlug('Ross 308'))->toBe('poulet')
        ->and(ProductionNorm::guessSpeciesSlug('Caille Japonaise'))->toBe('caille')
        ->and(ProductionNorm::guessSpeciesSlug('Dinde Chair (BUT 6)'))->toBe('dinde')
        ->and(ProductionNorm::guessSpeciesSlug('Tilapia du Nil'))->toBe('tilapia')
        ->and(ProductionNorm::guessSpeciesSlug('Mouton Djallonké'))->toBe('mouton')
        ->and(ProductionNorm::guessSpeciesSlug('Chèvre Saanen'))->toBe('chevre')
        // Souche inconnue → générique (null)
        ->and(ProductionNorm::guessSpeciesSlug('Marque Inconnue XYZ'))->toBeNull();
});

test('le scope forSpecies exclut les souches des autres espèces mais garde les génériques', function () {
    $poulet = Species::firstOrCreate(['slug' => 'poulet'], ['name_fr' => 'Poulet', 'is_active' => true]);
    $caille = Species::firstOrCreate(['slug' => 'caille'], ['name_fr' => 'Caille', 'is_active' => true]);

    $pouletNorm = ProductionNorm::create([
        'species_id' => $poulet->id, 'batch_type' => 'ponte',
        'week_number' => 1, 'phase_name' => 'Ponte', 'model_name' => 'ISA Brown',
    ]);
    $cailleNorm = ProductionNorm::create([
        'species_id' => $caille->id, 'batch_type' => 'ponte',
        'week_number' => 1, 'phase_name' => 'Ponte', 'model_name' => 'Caille Japonaise',
    ]);
    $genericNorm = ProductionNorm::create([
        'species_id' => null, 'batch_type' => 'ponte',
        'week_number' => 1, 'phase_name' => 'Ponte', 'model_name' => 'Souche Générique',
    ]);

    $names = ProductionNorm::forSpecies($poulet->id)->pluck('model_name')->all();

    expect($names)->toContain('ISA Brown')          // espèce du lot
        ->toContain('Souche Générique')             // générique (toutes espèces)
        ->not->toContain('Caille Japonaise');       // autre espèce → exclue
});
