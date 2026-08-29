<?php

use App\Actions\Utility\RecordEnergyReading;
use App\Models\EnergyReading;
use App\Models\EnergySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN INDICATEUR QUI NE POUVAIT PAS VARIER.
 *
 * `energy_readings.kwh_produced` existait de bout en bout — sauf à la saisie :
 *
 *   • la colonne est créée depuis la migration du 25/05, le modèle la déclare
 *     et la caste ;
 *   • `UtilityController::storeEnergyReading` la VALIDE ;
 *   • `UtilityService` la somme pour deux indicateurs : `total_kwh` et
 *     `edg_value`, « l'économie réalisée en autoproduisant plutôt qu'en
 *     achetant au réseau » ;
 *   • le tableau de bord Énergie affiche cette tuile, conditionnée au seul
 *     paramètre `energie.kwh_price_edg` — semé à 1 500 GNF/kWh, donc TOUJOURS
 *     vraie ;
 *   • et AUCUN formulaire, web ou mobile, ne proposait le champ.
 *
 * La tuile « Valeur produite (éq. EDG) » affichait donc en permanence 0,
 * sous-titrée « 0 kWh × 1 500 ». Elle ne pouvait pas afficher autre chose.
 *
 * ─── LA RÈGLE ───
 *
 * Un indicateur qui ne peut pas varier n'informe pas : il apprend à ne plus
 * regarder. Deux moitiés à la correction, et il faut les deux :
 *
 *   • le CHAMP, facultatif — tous les groupes n'ont pas de compteur kWh
 *     lisible, et exiger une saisie impossible serait pire que le zéro ;
 *   • la TUILE, masquée tant qu'aucun kWh n'a été relevé — un zéro permanent
 *     ressemble à une panne.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->groupe = EnergySource::create([
        'farm_id'          => $this->farm->id,
        'name'             => 'Groupe Perkins',
        'type'             => 'groupe',
        'status'           => 'operationnel',
        'total_hours_run'  => 0,
        'current_fuel_level' => 400,
        'fuel_tank_capacity' => 500,
    ]);
});

test('le FORMULAIRE web propose le champ kWh', function () {
    /*
     * L'écrivain manquant. Sans lui, la colonne restait vide quoi qu'il arrive.
     */
    $this->get(route('utilities.energy.sources'))
        ->assertOk()
        ->assertSee('name="kwh_produced"', false);
});

test('un relevé saisi avec des kWh les ENREGISTRE', function () {
    app(RecordEnergyReading::class)->execute([
        'energy_source_id' => $this->groupe->id,
        'reading_date'     => today()->toDateString(),
        'hours_run'        => 8,
        'kwh_produced'     => 120,
    ], $this->adminUser->id);

    expect((float) EnergyReading::where('energy_source_id', $this->groupe->id)->value('kwh_produced'))
        ->toBe(120.0);
});

test('la TUILE reste masquée tant qu’aucun kWh n’est relevé', function () {
    /*
     * Le zéro permanent, qui ressemble à une panne. Le tarif seul ne suffit pas
     * à justifier l'affichage : il est semé, donc toujours présent.
     */
    app(RecordEnergyReading::class)->execute([
        'energy_source_id' => $this->groupe->id,
        'reading_date'     => today()->toDateString(),
        'hours_run'        => 8,
    ], $this->adminUser->id);

    $this->get(route('utilities.dashboard'))
        ->assertOk()
        ->assertDontSee('Valeur produite');
});

test('elle APPARAÎT dès qu’un kWh est relevé', function () {
    // La borne inverse : l'exploitation qui a un compteur obtient son indicateur.
    app(RecordEnergyReading::class)->execute([
        'energy_source_id' => $this->groupe->id,
        'reading_date'     => today()->toDateString(),
        'hours_run'        => 8,
        'kwh_produced'     => 120,
    ], $this->adminUser->id);

    $this->get(route('utilities.dashboard'))
        ->assertOk()
        ->assertSee('Valeur produite');
});

test('le champ reste FACULTATIF — un relevé sans compteur passe', function () {
    /*
     * La garde qui compte pour le terrain : tous les groupes n'ont pas de
     * compteur kWh. Exiger une saisie impossible aurait été pire que le zéro
     * qu'on corrige.
     */
    app(RecordEnergyReading::class)->execute([
        'energy_source_id' => $this->groupe->id,
        'reading_date'     => today()->toDateString(),
        'hours_run'        => 6,
    ], $this->adminUser->id);

    expect(EnergyReading::where('energy_source_id', $this->groupe->id)->exists())->toBeTrue();
});
