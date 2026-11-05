<?php

use App\Models\Building;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE VIDE SANITAIRE FINIT LE MÊME JOUR POUR LA MISE EN PLACE, LA LIBÉRATION
 * ET L'ALERTE.
 *
 * Les jours restants (et la garde de mise en place) comparaient des DATES ; la
 * libération automatique et l'alerte « vide sanitaire dépassé » des INSTANTS.
 * Vide commencé à 23 h : le jour J+N à 8 h, la bande pouvait s'installer, mais
 * le bâtiment restait « En désinfection » et l'alerte se taisait jusqu'à 23 h.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    Carbon::setTestNow(today()->setTime(8, 0));
    $jours = Building::sanitaryBreakDays();

    $this->building->update([
        'status' => Building::STATUS_DESINFECTION,
        'disinfection_started_at' => today()->subDays($jours)->setTime(23, 0),
    ]);
});

afterEach(fn () => Carbon::setTestNow());

test('le jour J+N à 8 h : mise en place permise, bâtiment libéré, et l’alerte le compte', function () {
    $batiment = $this->building->fresh();

    expect($batiment->sanitary_break_remaining_days)->toBe(0)
        ->and($batiment->refusDeMiseEnPlace())->toBeNull()
        ->and(Building::videSanitaireEchu()->pluck('id'))->toContain($batiment->id);

    $this->artisan('farm:release-buildings')->assertSuccessful();

    expect($this->building->fresh()->status)->toBe(Building::STATUS_VIDE);
});

test('la veille, le vide court encore partout — non-régression', function () {
    Carbon::setTestNow(today()->subDay()->setTime(8, 0));

    $batiment = $this->building->fresh();
    expect($batiment->refusDeMiseEnPlace())->not->toBeNull()
        ->and(Building::videSanitaireEchu()->pluck('id'))->not->toContain($batiment->id);

    $this->artisan('farm:release-buildings')->assertSuccessful();
    expect($this->building->fresh()->status)->toBe(Building::STATUS_DESINFECTION);
});
