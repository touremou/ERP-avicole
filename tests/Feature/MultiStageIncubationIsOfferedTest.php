<?php

use App\Actions\Incubation\StartIncubation;
use App\Models\Batch;
use App\Models\Incubation;
use App\Models\Incubator;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ÉCRAN INTERDISAIT UNE PRATIQUE QUE LE SERVEUR AUTORISE.
 *
 * Le sélecteur de machine désactivait toute couveuse portant un cycle non clos —
 * un refus PUR. La validation serveur écarte pourtant ce choix mot pour mot :
 *
 *   « POURQUOI ON N'INTERDIT PAS PUREMENT LE SECOND CYCLE […] un incubateur
 *     accueille couramment plusieurs mises à couver à des dates différentes
 *     (incubation multi-étages). Refuser bloquerait une pratique légitime ; ce
 *     qu'il faut empêcher, c'est le DÉPASSEMENT. »
 *
 * Mesuré, sur une machine de 10 000 œufs portant un cycle de 2 000 — donc 8 000
 * places libres :
 *
 *   • l'écran rendait `<option value="1" disabled>` ;
 *   • le serveur, lui, acceptait sans broncher un second cycle de 3 000.
 *
 * La machine restait donc inutilisable trois semaines, alors qu'elle était à un
 * cinquième de sa charge.
 *
 * ─── QUATRE DÉCLARATIONS CONTRE UNE ───
 *
 * `Incubator::remainingCapacity()`, la borne de `StartIncubationRequest`, et les
 * deux Actions qui ne libèrent la machine que lorsqu'elle est RÉELLEMENT vide
 * (`eggsInIncubation() === 0`) sont toutes écrites pour la cohabitation de
 * cycles. Seul le sélecteur — la seule porte d'entrée réelle — disait l'inverse.
 *
 * L'écran lit désormais `remainingCapacity()` plutôt qu'une variante, et annonce
 * la place restante : c'est le chiffre dont l'opérateur a besoin, et celui que le
 * message d'erreur de la Request lui opposerait en cas de dépassement.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
    ]);

    $this->machine = Incubator::create([
        'farm_id' => $this->farm->id, 'name' => 'Couveuse A',
        'capacity' => 10_000, 'status' => 'Disponible',
    ]);

    Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'L', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 1_000, 'alert_threshold' => 0,
    ]);
});

/** Met un cycle en couveuse et rend la machine rafraîchie. */
function couverDansLaMachine(int $lotId, Incubator $machine, int $oeufs): Incubator
{
    (new StartIncubation())->execute([
        'incubator_id' => $machine->id,
        'batch_id'     => $lotId,
        'provider_id'  => null,
        'start_date'   => today()->toDateString(),
        'eggs_count'   => $oeufs,
        'source_type'  => 'internal',
        'egg_grade'    => 'L',
        'duration'     => 21,
    ]);

    return $machine->fresh();
}

/** La balise `<option>` de cette machine dans le sélecteur de l'écran. */
function optionDeLaMachine(object $test, int $machineId): string
{
    $html = $test->get(route('incubations.index'))->getContent();

    preg_match('/<option value="' . $machineId . '"[^>]*>/', $html, $trouve);

    return $trouve[0] ?? '(option absente)';
}

test('une machine à moitié pleine reste SÉLECTIONNABLE', function () {
    /*
     * LE défaut : 8 000 places libres, et l'option désactivée pendant 21 jours.
     */
    $machine = couverDansLaMachine($this->lot->id, $this->machine, 2_000);

    // Le décor, vérifié : c'est la place restante qui doit décider.
    expect($machine->remainingCapacity())->toBe(8_000);

    expect(optionDeLaMachine($this, $machine->id))->not->toContain('disabled');
});

test('une machine PLEINE reste refusée — la borne', function () {
    /*
     * On remplace un refus trop large par le bon : ce qu'il faut empêcher, c'est
     * le dépassement. Une machine sans place ne se propose pas.
     */
    $machine = couverDansLaMachine($this->lot->id, $this->machine, 10_000);

    expect($machine->remainingCapacity())->toBe(0)
        ->and(optionDeLaMachine($this, $machine->id))->toContain('disabled');
});

test('une machine en MAINTENANCE reste refusée — non-régression', function () {
    // L'autre motif de refus, qui n'a rien à voir avec la place.
    $this->machine->update(['status' => 'Maintenance']);

    expect(optionDeLaMachine($this, $this->machine->id))->toContain('disabled');
});

test('l’écran annonce la place restante', function () {
    /*
     * C'est le chiffre dont l'opérateur a besoin pour décider quoi charger — et
     * celui que le message d'erreur de la validation lui opposerait.
     */
    couverDansLaMachine($this->lot->id, $this->machine, 2_000);

    $this->get(route('incubations.index'))
        ->assertOk()
        ->assertSee('reste 8 000', false);
});

test('le SERVEUR acceptait déjà ce second cycle — non-régression', function () {
    /*
     * L'écran était seul à refuser : la borne serveur, elle, est la place
     * restante. Elle ne doit pas bouger.
     */
    $machine = couverDansLaMachine($this->lot->id, $this->machine, 2_000);

    $this->post(route('incubations.store'), [
        'incubator_id' => $machine->id,
        'batch_id'     => $this->lot->id,
        'start_date'   => today()->toDateString(),
        'eggs_count'   => 3_000,
        'source_type'  => 'internal',
        'egg_grade'    => 'L',
    ])->assertSessionHasNoErrors();

    expect(Incubation::withoutGlobalScopes()->count())->toBe(2)
        ->and($machine->fresh()->remainingCapacity())->toBe(5_000);
});

test('le DÉPASSEMENT reste refusé par le serveur — non-régression', function () {
    // La règle que la validation existe pour tenir : 8 000 places, 9 000 demandés.
    $machine = couverDansLaMachine($this->lot->id, $this->machine, 2_000);

    $this->post(route('incubations.store'), [
        'incubator_id' => $machine->id,
        'batch_id'     => $this->lot->id,
        'start_date'   => today()->toDateString(),
        'eggs_count'   => 9_000,
        'source_type'  => 'internal',
        'egg_grade'    => 'L',
    ])->assertSessionHasErrors('eggs_count');

    expect(Incubation::withoutGlobalScopes()->count())->toBe(1);
});
