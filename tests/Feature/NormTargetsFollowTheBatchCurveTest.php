<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Models\Species;
use Database\Seeders\ProductionNormSeeder;
use Database\Seeders\SpeciesSeeder;
use Illuminate\Support\Carbon;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES CIBLES SUIVENT LA COURBE DE LA BANDE — SA SOUCHE, SON ESPÈCE, ENTRE LES PALIERS.
 *
 * Relevé par l'audit après #408 :
 *   • l'âge de ponte se repliait sur TOUTES les espèces — la caille pond dès la
 *     semaine 6 : une poulette de 5 semaines pouvait voir ses œufs collectés ;
 *   • la souche s'appariait par LIKE (« Lohmann » : deux souches) ;
 *   • les cibles se cherchaient à la semaine EXACTE : entre deux paliers d'une
 *     courbe (Cou Nu : 2/4/8/12/16), cible 0 — barre verte, alerte muette ;
 *   • sans souche, le conseiller mélangeait plusieurs souches ;
 *   • l'analyse HDP comptait les semaines autrement que le reste (floor).
 *
 * Sur les vrais semeurs.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(SpeciesSeeder::class);
    $this->seed(ProductionNormSeeder::class);
    $this->poulet = Species::where('slug', 'poulet')->first();
});

function bandeDe(object $t, string $slugType, ?string $souche, int $ageJours): Batch
{
    return Batch::factory()->create([
        'building_id' => Building::factory()->create(['type' => $slugType, 'capacity' => 10_000, 'farm_id' => session('current_farm_id')])->id,
        'species_id' => $t->poulet->id,
        'production_type_id' => ProductionType::where('species_id', $t->poulet->id)->where('slug', $slugType)->value('id'),
        'model_name' => $souche,
        'arrival_date' => Carbon::today()->subDays($ageJours - 1), 'birth_date' => null,
    ]);
}

test('une poulette SANS souche n’hérite pas de l’âge de ponte de la caille', function () {
    $poulettes = bandeDe($this, 'ponte', null, 35);

    // La caille pond dès la semaine 6 (35 j) ; la poule, non.
    expect($poulettes->minLayingAgeDays())->toBeGreaterThan(100)
        ->and($poulettes->canCollectEggs())->toBeFalse();
});

test('la souche s’apparie par son nom EXACT — « Lohmann » n’est pas « Lohmann Brown »', function () {
    $exacte = bandeDe($this, 'ponte', 'Lohmann Brown', 10);
    $approchee = bandeDe($this, 'ponte', 'Lohmann', 10);

    $semaineBrown = ProductionNorm::where('model_name', 'Lohmann Brown')->where('target_laying_rate', '>', 0)->min('week_number');

    expect($exacte->minLayingAgeDays())->toBe(((int) $semaineBrown - 1) * 7)
        ->and(ProductionNorm::courbePour($approchee)->pluck('model_name')->unique()->count())->toBeLessThanOrEqual(1);
});

test('entre deux paliers de la courbe, la cible est INTERPOLÉE — plus jamais 0', function () {
    // Cou Nu : semaine 4 = 300 g, semaine 8 = 700 g → semaine 6 ≈ 500 g.
    $couNu = bandeDe($this, 'chair', 'Poulet local Cou Nu', 40);

    expect($couNu->semaineDAge())->toBe(6)
        ->and(ProductionNorm::cibleA($couNu)['weight'])->toBe(500.0);
});

test('sans souche, la courbe est celle d’UNE souche, jamais un mélange', function () {
    $sansSouche = bandeDe($this, 'chair', null, 28);

    expect(ProductionNorm::courbePour($sansSouche)->pluck('model_name')->unique()->count())->toBe(1);
});

test('la semaine d’âge est la même partout : jour 134 = semaine 20', function () {
    expect(bandeDe($this, 'ponte', 'Lohmann Brown', 134)->semaineDAge())->toBe(20);

    $source = file_get_contents(app_path('Services/EggAnalysisService.php'));
    expect(str_contains($source, 'floor($batch->age / 7)'))->toBeFalse();
});
