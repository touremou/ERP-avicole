<?php

use App\Models\Batch;

uses(Tests\TestCase::class);

test('les valeurs des constantes de statut restent stables (valeurs stockées en base)', function () {
    // Garde-fou : un renommage involontaire casserait les enregistrements
    // existants en base, dont la colonne `status` contient ces littéraux.
    expect(Batch::STATUS_ACTIF)->toBe('Actif')
        ->and(Batch::STATUS_TERMINE)->toBe('Terminé')
        ->and(Batch::STATUS_CLOTURE)->toBe('Clôturé')
        ->and(Batch::STATUS_VENDU)->toBe('Vendu')
        ->and(Batch::STATUS_ANNULE)->toBe('Annulé');
});

test('STATUS_ARCHIVED regroupe tous les statuts non actifs', function () {
    expect(Batch::STATUS_ARCHIVED)->toBe([
        Batch::STATUS_TERMINE,
        Batch::STATUS_CLOTURE,
        Batch::STATUS_VENDU,
        Batch::STATUS_ANNULE,
    ])->not->toContain(Batch::STATUS_ACTIF);
});

test('EDITABLE_STATUSES ne contient que des statuts connus', function () {
    $known = [
        Batch::STATUS_ACTIF,
        Batch::STATUS_TERMINE,
        Batch::STATUS_CLOTURE,
        Batch::STATUS_VENDU,
        Batch::STATUS_ANNULE,
    ];

    foreach (Batch::EDITABLE_STATUSES as $status) {
        expect($known)->toContain($status);
    }
});

test('isActive et isArchived reflètent le statut du lot', function () {
    $batch = new Batch(['status' => Batch::STATUS_ACTIF]);
    expect($batch->isActive())->toBeTrue()
        ->and($batch->isArchived())->toBeFalse();

    $batch->status = Batch::STATUS_TERMINE;
    expect($batch->isActive())->toBeFalse()
        ->and($batch->isArchived())->toBeTrue();

    $batch->status = Batch::STATUS_ANNULE;
    expect($batch->isArchived())->toBeTrue();
});
