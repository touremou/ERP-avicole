<?php

use App\Models\Batch;
use App\Models\ProductionType;
use App\Models\Species;

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

test('feedSector classe les types ruminants/lapin/porc dans le bon secteur', function () {
    $chevreId = Species::where('slug', 'chevre')->value('id');
    $moutonId = Species::where('slug', 'mouton')->value('id');

    $engraissement = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('engraissement', $moutonId)->id]);
    $laitiere = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('laitiere', $chevreId)->id]);
    $reproducteur = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('reproducteur', $chevreId)->id]);

    expect($engraissement->feedSector())->toBe('Engraissement')
        ->and($laitiere->feedSector())->toBe('Laitière')
        ->and($reproducteur->feedSector())->toBe('Reproducteur');
});

test('feedSector classe les types aquaculture dans le bon secteur', function () {
    $tilapiaId = Species::where('slug', 'tilapia')->value('id');

    $grossissement = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('grossissement', $tilapiaId)->id]);
    $alevinage = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('alevinage', $tilapiaId)->id]);

    expect($grossissement->feedSector())->toBe('Grossissement')
        ->and($alevinage->feedSector())->toBe('Alevinage');
});

test('feedPhases renvoie la liste correspondant au secteur du lot', function () {
    $chevreId = Species::where('slug', 'chevre')->value('id');

    $chair = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('chair', null)->id]);
    $ponte = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('ponte', null)->id]);
    $laitiere = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('laitiere', $chevreId)->id]);

    expect($chair->feedPhases())->toBe(Batch::FEED_PHASES['Chair'])
        ->and($ponte->feedPhases())->toBe(Batch::FEED_PHASES['Ponte'])
        ->and($laitiere->feedPhases())->toBe(Batch::FEED_PHASES['Laitière']);
});

test('FEED_PHASES couvre les secteurs volaille, ruminants et aquaculture', function () {
    expect(array_keys(Batch::FEED_PHASES))->toBe([
        'Chair', 'Ponte', 'Reproducteur', 'Engraissement', 'Laitière', 'Grossissement', 'Alevinage',
    ]);

    foreach (Batch::FEED_PHASES as $sector => $phases) {
        expect($phases)->not->toBeEmpty();
    }
});

test('feedPreselectPhase choisit la phase selon l\'âge et le secteur', function () {
    $chair = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('chair', null)->id]);
    $ponte = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('ponte', null)->id]);

    expect($chair->feedPreselectPhase(7))->toBe('Chair Démarrage')
        ->and($chair->feedPreselectPhase(20))->toBe('Chair Croissance')
        ->and($chair->feedPreselectPhase(40))->toBe('Chair Finition')
        ->and($ponte->feedPreselectPhase(30))->toBe('Ponte Démarrage (Poussin)')
        ->and($ponte->feedPreselectPhase(100))->toBe('Ponte Croissance (Poulette)')
        ->and($ponte->feedPreselectPhase(300))->toBe('Ponte 1 (Pic de ponte)');
});

test('feedPreselectPhase se cale sur la durée de cycle réelle de l\'espèce', function () {
    $dindeId = Species::where('slug', 'dinde')->value('id');

    // Même secteur (Chair) mais cycles différents : poulet 45 j, dinde 120 j.
    $poulet = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('chair', null)->id]);
    $dinde = Batch::factory()->create(['production_type_id' => ProductionType::resolveOrCreate('chair', $dindeId)->id]);

    // À 30 j : le poulet (cycle 45) est déjà en finition (>0,60×45=27),
    // la dinde (cycle 120) encore en démarrage (≤0,30×120=36).
    expect($poulet->feedPreselectPhase(30))->toBe('Chair Finition')
        ->and($dinde->feedPreselectPhase(30))->toBe('Chair Démarrage')
        ->and($dinde->feedPreselectPhase(50))->toBe('Chair Croissance')
        ->and($dinde->feedPreselectPhase(110))->toBe('Chair Finition');
});
