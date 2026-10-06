<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\ProductionType;
use App\Models\Species;
use Database\Seeders\ProductionNormSeeder;
use Database\Seeders\SpeciesSeeder;
use Illuminate\Support\Carbon;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA PHASE DE VIE SUIT LA BANDE — SA DURÉE, SON ÂGE DE PONTE.
 *
 * Elle se lisait sur des âges fixes de POULET pour toutes les espèces : une
 * dinde (140 j) « Finition » dès le jour 29, une caille en ponte (semaine 6)
 * « Croissance » avec un aliment de poulette, un bélier en « pré-ponte ». La
 * phase, l'aliment présélectionné et le seuil de mortalité lisent désormais la
 * même durée (Batch::cycleDays) et le même âge de ponte (minLayingAgeDays).
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(SpeciesSeeder::class);
    $this->seed(ProductionNormSeeder::class);
});

function bandeEspece(string $espece, string $type, ?string $souche, int $ageJours): Batch
{
    $sp = Species::where('slug', $espece)->first();

    return Batch::factory()->create([
        'building_id' => Building::factory()->create(['type' => $type, 'capacity' => 10_000, 'farm_id' => session('current_farm_id')])->id,
        'species_id' => $sp->id,
        'production_type_id' => ProductionType::where('species_id', $sp->id)->where('slug', $type)->value('id'),
        'model_name' => $souche,
        'arrival_date' => Carbon::today()->subDays($ageJours - 1), 'birth_date' => null,
    ]);
}

test('une dinde BUT 6 de 30 jours n’est pas en finition', function () {
    $dinde = bandeEspece('dinde', 'chair', 'Dinde BUT 6', 30);

    expect($dinde->current_phase)->toBe('Démarrage')                 // 30 ≤ 0,3 × 140
        ->and($dinde->feedPreselectPhase(30))->toContain('Démarrage');
});

test('une caille pondeuse de 50 jours est EN PONTE, avec l’aliment de ponte', function () {
    $caille = bandeEspece('caille', 'ponte', 'Caille Japonaise', 50);

    expect($caille->current_phase)->toBe('Ponte')
        ->and($caille->feedPreselectPhase(50))->toContain('Ponte 1');
});

test('le poulet de chair Ross reste découpé comme avant — non-régression', function () {
    $ross = bandeEspece('poulet', 'chair', 'Ross 308', 40);

    expect(bandeEspece('poulet', 'chair', 'Ross 308', 10)->current_phase)->toBe('Démarrage')
        ->and(bandeEspece('poulet', 'chair', 'Ross 308', 20)->current_phase)->toBe('Croissance')
        ->and($ross->current_phase)->toBe('Finition');
});

test('la poule pondeuse garde ses bornes : croissance jusqu’à 126 j — non-régression', function () {
    expect(bandeEspece('poulet', 'ponte', 'ISA Brown', 100)->current_phase)->toBe('Croissance');
});

test('un bélier reproducteur n’a pas de « pré-ponte »', function () {
    expect(bandeEspece('mouton', 'reproducteur', null, 140)->current_phase)->toBe('Production');
});

test('le seuil de mortalité de croissance suit la durée de la bande', function () {
    // Cou Nu (112 j) au jour 40 : encore en croissance (≤ 0,6 × 112) — et non
    // « finition » comme au-delà de 28 j pour toutes les souches.
    $couNu = bandeEspece('poulet', 'chair', 'Poulet local Cou Nu', 40);

    expect(Batch::dailyMortalityPhaseKey('Chair', 40, (int) floor(0.6 * $couNu->cycleDays())))
        ->toBe('mortality_pct_chair_croissance');
});
