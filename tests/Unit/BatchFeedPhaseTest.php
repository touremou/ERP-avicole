<?php

use App\Models\Batch;
use App\Models\ProductionType;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);
});

test('feedSector classe les types volaille dans le bon secteur', function () {
    $chair = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('chair', null)->id]);
    $poussiniere = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('poussiniere', null)->id]);
    $ponte = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('ponte', null)->id]);
    $reproducteur = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('reproducteur', null)->id]);
    $repro = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('repro', null)->id]);

    expect($chair->feedSector())->toBe('Chair')
        ->and($poussiniere->feedSector())->toBe('Chair')
        ->and($ponte->feedSector())->toBe('Ponte')
        ->and($reproducteur->feedSector())->toBe('Ponte')
        ->and($repro->feedSector())->toBe('Ponte');
});

test('feedPhases renvoie la liste correspondant au secteur du lot', function () {
    $chair = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('chair', null)->id]);
    $ponte = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('ponte', null)->id]);

    expect($chair->feedPhases())->toBe(Batch::FEED_PHASES['Chair'])
        ->and($ponte->feedPhases())->toBe(Batch::FEED_PHASES['Ponte']);
});

test('FEED_PHASES ne contient que les secteurs Chair et Ponte', function () {
    expect(array_keys(Batch::FEED_PHASES))->toBe(['Chair', 'Ponte'])
        ->and(Batch::FEED_PHASES['Chair'])->not->toBeEmpty()
        ->and(Batch::FEED_PHASES['Ponte'])->not->toBeEmpty();
});
