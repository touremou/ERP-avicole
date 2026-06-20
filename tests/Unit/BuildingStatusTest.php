<?php

use App\Models\Building;

uses(Tests\TestCase::class);

test('les valeurs des constantes de statut restent stables (valeurs stockées en base)', function () {
    // Garde-fou : un renommage involontaire casserait les enregistrements
    // existants en base, dont la colonne `status` contient ces littéraux.
    expect(Building::STATUS_VIDE)->toBe('Vide')
        ->and(Building::STATUS_DISPONIBLE)->toBe('Disponible')
        ->and(Building::STATUS_OCCUPE)->toBe('Occupé')
        ->and(Building::STATUS_DESINFECTION)->toBe('En désinfection')
        ->and(Building::STATUS_MAINTENANCE)->toBe('Maintenance');
});

test('STATUS_AVAILABLE regroupe les statuts libres', function () {
    expect(Building::STATUS_AVAILABLE)->toBe([
        Building::STATUS_VIDE,
        Building::STATUS_DISPONIBLE,
    ])->not->toContain(Building::STATUS_OCCUPE);
});

test('isInSanitaryBreak reflète le statut de désinfection', function () {
    $building = new Building(['status' => Building::STATUS_DESINFECTION]);
    expect($building->isInSanitaryBreak())->toBeTrue();

    $building->status = Building::STATUS_VIDE;
    expect($building->isInSanitaryBreak())->toBeFalse();
});

test('le badge de couleur couvre tous les statuts connus', function () {
    foreach ([Building::STATUS_VIDE, Building::STATUS_DISPONIBLE, Building::STATUS_OCCUPE, Building::STATUS_DESINFECTION, Building::STATUS_MAINTENANCE] as $status) {
        $building = new Building(['status' => $status]);
        expect($building->status_color)->not->toBe('slate');
    }
});
