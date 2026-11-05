<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\ProductionType;
use App\Models\Species;
use App\Services\DashboardInsightsService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'INDICE DE CONSOMMATION — UN SEUL CHIFFRE POUR UN LOT.
 *
 * `Batch::fcr_corrected` se présentait comme l'« IMPLÉMENTATION UNIQUE » : elle
 * l'était pour le rapport technique, la fiche hebdomadaire par technicien et la
 * vue consolidée cross-sites. Elle impute conventionnellement aux sujets morts la
 * moitié du poids moyen courant, sans quoi un lot ayant subi de la mortalité est
 * DOUBLEMENT pénalisé : ces sujets ont mangé sans figurer au dénominateur.
 *
 * Mais deux écrans affichaient, sous le même intitulé « indice de consommation »,
 * un chiffre calculé sur la seule biomasse VIVANTE :
 *
 *   • la FICHE DU LOT (accessor `fcr`, une seconde formule) ;
 *   • le TABLEAU DE BORD (« IC moyen », agrégat maison).
 *
 * Trois écrans, trois valeurs pour un même lot — et l'écart allait toujours dans
 * le même sens : la fiche et le tableau de bord paraissaient plus mauvais que le
 * rapport, du montant exact de la correction de mortalité. De quoi décider un
 * changement d'aliment sur un chiffre faux.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Lot pesé, nourri, avec de la mortalité — le cas où les formules divergent. */
function convertedBatch(int $initial = 1000, int $deaths = 100, float $feedKg = 3000, float $avgWeightKg = 2.0): Batch
{
    $species = Species::firstOrCreate(['slug' => 'poulet'], ['name_fr' => 'Poulet', 'is_active' => true]);

    $batch = Batch::factory()->create([
        'farm_id'            => session('current_farm_id'),
        'building_id'        => Building::factory()->create(['farm_id' => session('current_farm_id')])->id,
        'species_id'         => $species->id,
        'production_type_id' => ProductionType::resolveOrCreate('chair', $species->id)->id,
        'status'             => 'Actif',
        'arrival_date'       => now()->subDays(35)->toDateString(),
        'initial_quantity'   => $initial,
        'current_quantity'   => $initial,
        'qty_dead'           => 0,
    ]);

    DailyCheck::create([
        'batch_id' => $batch->id, 'check_date' => today()->subDay(),
        'mortality' => $deaths, 'feed_consumed' => $feedKg,
        'avg_weight' => $avgWeightKg, 'feed_unit_cost' => 5000,
        'user_id' => \App\Models\User::query()->value('id'),
    ]);

    return $batch->fresh();
}

test('la biomasse produite compte la moitié du poids des sujets morts', function () {
    $batch = convertedBatch(initial: 1000, deaths: 100, avgWeightKg: 2.0);

    // 900 vivants × 2 kg + 100 morts × 1 kg = 1 900 kg.
    expect($batch->producedBiomassKg(2.0, 100))->toBe(1900.0);

    // Sans la correction, on aurait retenu 1 800 kg — donc un indice 5,5 % plus
    // mauvais, uniquement parce que le lot a eu de la mortalité.
    expect($batch->producedBiomassKg(2.0, 0))->toBe(2000.0);
});

test('sans pesée moyenne, l’indice n’est pas MESURABLE (et non nul)', function () {
    $batch = convertedBatch(avgWeightKg: 0);

    expect($batch->producedBiomassKg(0.0, 100))->toBeNull()
        ->and($batch->fcr_corrected)->toBeNull()
        ->and($batch->fcr)->toBeNull();
});

test('la fiche du lot et le rapport technique donnent le MÊME indice', function () {
    // C'est la divergence constatée : la fiche portait la variante « biomasse
    // vivante seule ».
    $batch = convertedBatch(initial: 1000, deaths: 100, feedKg: 3000, avgWeightKg: 2.0);

    // 3 000 / 1 900 = 1,58
    expect($batch->fcr_corrected)->toBe(1.58)
        ->and($batch->fcr)->toBe(1.58);

    $response = $this->actingAs($this->adminUser)->get(route('batches.show', $batch))->assertOk();
    expect($response->viewData('stats')['fcr'])->toBe(1.58);

    // Le rapport technique : même chiffre, lu dans ses données de vue (il rend le
    // séparateur décimal anglo-saxon, ce qui n'est pas l'objet de ce test).
    $report = $this->actingAs($this->adminUser)->get(route('reports.technical'))->assertOk();
    $reported = collect($report->viewData('stats'))->firstWhere('code', $batch->code);

    expect($reported['fcr'] ?? null)->toBe(1.58);
});

test('l’IC moyen du tableau de bord suit la même convention', function () {
    // L'agrégat divisait par la seule biomasse vivante : 3 000 / 1 800 = 1,67,
    // contre 1,58 au rapport, pour le même et unique lot.
    $batch = convertedBatch(initial: 1000, deaths: 100, feedKg: 3000, avgWeightKg: 2.0);

    $technical = app(DashboardInsightsService::class)
        ->technical(Batch::where('id', $batch->id)->get(), 10.0);

    expect($technical['fcr'])->toBe(1.58)
        ->and($technical['biomass_kg'] ?? null)->not->toBeNull();
});

test('la convention de biomasse n’existe qu’en un seul endroit', function () {
    // Le facteur 0,5 sur les sujets morts ne doit plus être recopié ailleurs.
    // Le tableau de bord passe par le calcul de groupe (Batch::consommationDe),
    // qui applique producedBiomassKg bande par bande.
    $insights = file_get_contents(app_path('Services/DashboardInsightsService.php'));
    $groupe   = file_get_contents(app_path('Models/Batch.php'));

    expect($insights)->toContain('consommationDe')
        ->and($insights)->not->toContain('current_quantity * $lastWeight')
        ->and($groupe)->toContain('producedBiomassKg((float) ($pesees');

    $controller = file_get_contents(app_path('Http/Controllers/BatchController.php'));
    expect($controller)->toContain('fcr_corrected');
});

test('un lot sans mortalité donne le même indice dans les deux formules', function () {
    // Contrôle de non-régression : la correction ne doit rien changer quand il n'y
    // a pas eu de perte.
    $batch = convertedBatch(initial: 1000, deaths: 0, feedKg: 3000, avgWeightKg: 2.0);

    // 3 000 / (1 000 × 2) = 1,50
    expect($batch->fcr_corrected)->toBe(1.5);
});

test('l’IC d’un groupe se pondère : Σ aliment ÷ Σ biomasse, sur le tableau de bord, la fiche du technicien et les sites', function () {
    // Une bande de 5 000 sujets à 1,50, un essai de 50 sujets à 3,00.
    $grande = convertedBatch(initial: 5000, deaths: 0, feedKg: 15000, avgWeightKg: 2.0);   // 15 000 / 10 000
    $essai  = convertedBatch(initial: 50, deaths: 0, feedKg: 300, avgWeightKg: 2.0);       // 300 / 100

    // (15 000 + 300) / (10 000 + 100) = 1,51 — la moyenne simple aurait dit 2,25.
    $groupe = Batch::consommationDe([$grande, $essai]);
    expect($groupe['fcr'])->toBe(1.51);

    $technical = app(DashboardInsightsService::class)
        ->technical(Batch::whereIn('id', [$grande->id, $essai->id])->get(), 0.0);
    expect($technical['fcr'])->toBe(1.51);

    // La fiche hebdomadaire du technicien : ses deux lots.
    $technicien = \App\Models\Employee::factory()->create(['status' => 'Actif']);
    Batch::whereIn('id', [$grande->id, $essai->id])->update(['employee_id' => $technicien->id]);
    $fiche = app(\App\Services\TechnicianWeekService::class)->forEmployee($technicien, now()->startOfWeek());
    expect(collect($fiche['indicators'])->firstWhere('key', 'fcr')['value'])->toBe(1.51);
});
