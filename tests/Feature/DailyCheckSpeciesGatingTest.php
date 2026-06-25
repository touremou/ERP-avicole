<?php

use App\Models\Batch;
use App\Models\Farm;
use App\Models\ProductionType;
use App\Models\Species;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);
});

/**
 * Lot rattaché à une espèce d'une famille donnée. Le type de production est la
 * source de vérité taxonomique (Batch::syncTaxonomyFromProductionType) : on le
 * résout pour l'espèce, sinon species_id serait réécrit au save.
 */
function batchOfSpeciesFamily(int $farmId, string $family): Batch
{
    $species = Species::firstOrCreate(
        ['slug' => $family . '-gating'],
        ['name_fr' => ucfirst($family), 'family' => $family, 'is_active' => true]
    );
    $type = ProductionType::resolveOrCreate($family, $species->id);

    return Batch::factory()->create([
        'farm_id'            => $farmId,
        'production_type_id' => $type->id,
        'status'             => 'Actif',
    ]);
}

test('le formulaire avicole montre litière, picage, boiterie et ambiance air', function () {
    $batch = batchOfSpeciesFamily($this->farm->id, 'volaille');

    expect($batch->usesLitter())->toBeTrue()
        ->and($batch->tracksPecking())->toBeTrue()
        ->and($batch->tracksLameness())->toBeTrue()
        ->and($batch->tracksAirAmbiance())->toBeTrue();
});

test('un lot piscicole masque litière, picage, boiterie et ambiance air', function () {
    $batch = batchOfSpeciesFamily($this->farm->id, 'aquaculture');

    expect($batch->usesLitter())->toBeFalse()
        ->and($batch->tracksPecking())->toBeFalse()
        ->and($batch->tracksLameness())->toBeFalse()
        ->and($batch->tracksAirAmbiance())->toBeFalse(); // milieu = eau (section qualité dédiée)
});

test('un ruminant garde la boiterie mais pas le picage ni la litière', function () {
    $batch = batchOfSpeciesFamily($this->farm->id, 'grand_ruminant');

    expect($batch->usesLitter())->toBeFalse()
        ->and($batch->tracksPecking())->toBeFalse()   // trouble propre aux volailles
        ->and($batch->tracksLameness())->toBeTrue()   // boiterie pertinente chez les mammifères
        ->and($batch->tracksAirAmbiance())->toBeTrue();
});

test('un lapin (litière profonde) garde la litière mais pas le picage', function () {
    $batch = batchOfSpeciesFamily($this->farm->id, 'lagomorphe');

    expect($batch->usesLitter())->toBeTrue()
        ->and($batch->tracksPecking())->toBeFalse()
        ->and($batch->tracksLameness())->toBeTrue();
});
