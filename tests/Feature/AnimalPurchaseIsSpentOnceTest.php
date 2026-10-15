<?php

use App\Models\Batch;
use App\Models\ProductionType;
use App\Models\Species;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ACHAT DES ANIMAUX EST DÉCAISSÉ UNE FOIS — LE MOIS DE L'ARRIVÉE.
 *
 * Le rapport des dépenses mensuelles reportait le coût d'acquisition complet
 * sur la carte de la bande dans CHAQUE mois de présence : une bande de
 * trois mois affichait trois fois son achat en « Total Décaissement », alors
 * que le récapitulatif annuel ne le comptait qu'une fois et que les
 * « Charges consolidées » du mois l'ignoraient. Une bande arrivée l'an passé
 * le comptait aussi dans l'année en cours, et un filtre sur un mois sans
 * arrivée le gardait dans le récapitulatif.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    $this->seed(Database\Seeders\SpeciesSeeder::class);

    $poulet = Species::where('slug', 'poulet')->first();
    $this->bande = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'species_id' => $poulet->id,
        'production_type_id' => ProductionType::where('slug', 'chair')->where('species_id', $poulet->id)->value('id'),
        'arrival_date' => '2026-03-10', 'birth_date' => '2026-03-10', 'closing_date' => '2026-05-20',
        'initial_quantity' => 1000, 'current_quantity' => 1000, 'total_acquisition_cost' => 900000,
    ]);
});

function depensesMensuelles(Tests\TestCase $test, array $filtres): array
{
    return $test->get(route('reports.monthly', $filtres))->assertOk()->viewData('monthlyData');
}

test('l’achat figure au seul mois d’arrivée', function () {
    $mois = depensesMensuelles($this, ['year' => 2026]);

    expect($mois[3][$this->bande->id]['acquisition_cost'])->toBe(900000.0)
        ->and($mois[4][$this->bande->id]['acquisition_cost'])->toEqual(0)
        ->and($mois[5][$this->bande->id]['acquisition_cost'])->toEqual(0);
});

test('les mois additionnés font le récapitulatif', function () {
    $reponse = $this->get(route('reports.monthly', ['year' => 2026]))->assertOk();
    $somme = collect($reponse->viewData('monthlyData'))->flatten(1)->sum('acquisition_cost');

    expect($reponse->viewData('globalStats')['acq_cost'])->toBe(900000.0)->and($somme)->toEqual(900000);
});

test('un mois sans arrivée ne porte pas l’achat', function () {
    $reponse = $this->get(route('reports.monthly', ['year' => 2026, 'month' => 4]))->assertOk();

    expect($reponse->viewData('globalStats')['acq_cost'])->toEqual(0);
});

test('une bande arrivée l’an passé n’est pas rachetée cette année', function () {
    $this->bande->update(['arrival_date' => '2025-12-01', 'birth_date' => '2025-12-01']);

    expect($this->get(route('reports.monthly', ['year' => 2026]))->viewData('globalStats')['acq_cost'])->toEqual(0)
        ->and($this->get(route('reports.monthly', ['year' => 2025]))->viewData('globalStats')['acq_cost'])->toBe(900000.0);
});

test('en plage libre, l’achat compte si l’arrivée est dans la plage', function () {
    $dans = $this->get(route('reports.monthly', ['date_from' => '2026-03-01', 'date_to' => '2026-04-30']));
    $apres = $this->get(route('reports.monthly', ['date_from' => '2026-04-01', 'date_to' => '2026-04-30']));

    expect($dans->viewData('globalStats')['acq_cost'])->toBe(900000.0)
        ->and($apres->viewData('globalStats')['acq_cost'])->toEqual(0);
});
