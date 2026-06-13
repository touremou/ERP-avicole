<?php

use App\Models\Batch;

uses(Tests\TestCase::class);

test('feedSector classe les types volaille dans le bon secteur', function () {
    expect((new Batch(['type' => 'chair']))->feedSector())->toBe('Chair')
        ->and((new Batch(['type' => 'poussiniere']))->feedSector())->toBe('Chair')
        ->and((new Batch(['type' => 'ponte']))->feedSector())->toBe('Ponte')
        ->and((new Batch(['type' => 'reproducteur']))->feedSector())->toBe('Ponte')
        ->and((new Batch(['type' => 'repro']))->feedSector())->toBe('Ponte');
});

test('feedPhases renvoie la liste correspondant au secteur du lot', function () {
    expect((new Batch(['type' => 'chair']))->feedPhases())->toBe(Batch::FEED_PHASES['Chair'])
        ->and((new Batch(['type' => 'ponte']))->feedPhases())->toBe(Batch::FEED_PHASES['Ponte']);
});

test('FEED_PHASES ne contient que les secteurs Chair et Ponte', function () {
    expect(array_keys(Batch::FEED_PHASES))->toBe(['Chair', 'Ponte'])
        ->and(Batch::FEED_PHASES['Chair'])->not->toBeEmpty()
        ->and(Batch::FEED_PHASES['Ponte'])->not->toBeEmpty();
});
