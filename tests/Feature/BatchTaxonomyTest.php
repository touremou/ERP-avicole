<?php

use App\Models\Batch;
use App\Models\ProductionType;
use App\Models\Species;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    // Le référentiel multiespèces est déjà alimenté par les migrations
    // (reseed_species_table) : on réutilise les enregistrements existants.
    $this->poulet = Species::firstOrCreate(
        ['slug' => 'poulet'],
        ['name_fr' => 'Poulet', 'family' => 'volaille', 'is_active' => true]
    );
    $this->ponteType = ProductionType::firstOrCreate(
        ['species_id' => $this->poulet->id, 'slug' => 'ponte'],
        ['name_fr' => 'Pondeuses', 'is_active' => true]
    );
});

test('la création dérive type et species_id du type de production rattaché', function () {
    // type volontairement incohérent : le production_type doit primer.
    $batch = Batch::factory()->create([
        'type'               => 'chair',
        'species_id'         => null,
        'production_type_id' => $this->ponteType->id,
    ]);

    expect($batch->type)->toBe('ponte')
        ->and($batch->species_id)->toBe($this->poulet->id);
});

test('changer le type de production resynchronise type et species_id', function () {
    $chairType = ProductionType::firstOrCreate(
        ['species_id' => $this->poulet->id, 'slug' => 'chair'],
        ['name_fr' => 'Chair', 'is_active' => true]
    );

    $batch = Batch::factory()->create(['production_type_id' => $this->ponteType->id]);
    expect($batch->type)->toBe('ponte');

    $batch->update(['production_type_id' => $chairType->id]);
    expect($batch->fresh()->type)->toBe('chair');
});

test('sans type de production, le type legacy soumis est conservé', function () {
    $batch = Batch::factory()->create([
        'type'               => 'poussiniere',
        'production_type_id' => null,
    ]);

    expect($batch->type)->toBe('poussiniere')
        ->and($batch->production_type_id)->toBeNull();
});
