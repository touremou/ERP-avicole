<?php

use App\Models\Batch;
use App\Models\Incubation;
use App\Models\Incubator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES TAUX DU COUVOIR SE PONDÈRENT PAR LES ŒUFS.
 *
 * Décision de l'exploitation : la moyenne SIMPLE des taux de chaque cycle
 * faussait les indicateurs. Un essai de 30 œufs à 50 % d'éclosion pesait
 * autant qu'une couvée de 900 à 90 % : « 70 % » au lieu de 88,7 %.
 *
 *   Taux d'éclosion moyen = Σ poussins nés ÷ Σ œufs mis en incubation × 100
 *   Fertilité moyenne     = Σ œufs fertiles ÷ Σ œufs mis en incubation × 100
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
    ]);
    $this->machine = Incubator::create([
        'farm_id' => $this->farm->id, 'name' => 'Couveuse P', 'capacity' => 50_000, 'status' => 'Disponible',
    ]);

    $cycle = fn (string $code, int $oeufs, int $fertiles, int $nes) => Incubation::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id, 'incubator_id' => $this->machine->id,
        'code_incubation' => $code, 'start_date' => now()->subDays(25)->toDateString(),
        'hatch_date_expected' => now()->subDays(4)->toDateString(),
        'eggs_count' => $oeufs, 'fertile_eggs' => $fertiles, 'hatched_chicks' => $nes,
        'status' => 'clos', 'finished_at' => now()->subDays(4),
    ]);

    $cycle('INC-ESSAI', 30, 24, 15);       // essai : 50 % d'éclosion, 80 % de fertilité
    $cycle('INC-COUVEE', 900, 864, 810);   // couvée : 90 % d'éclosion, 96 % de fertilité
});

test('le Couvoir annonce l’éclosion pondérée : Σ nés ÷ Σ œufs mis en incubation', function () {
    $stats = $this->get(route('incubations.index'))->assertOk()->viewData('stats');

    // (15 + 810) / (30 + 900) = 88,7 % — la moyenne simple aurait dit 70 %.
    expect($stats['avg_reussite'])->toBe(88.7)
        // (24 + 864) / 930 = 95,5 % — la moyenne simple aurait dit 88 %.
        ->and($stats['avg_fertility'])->toBe(95.5)
        ->and($stats['machine_performance']->first()['avg_hatch'])->toBe(88.7);
});

test('la fiabilité de l’incubateur dit le même taux pondéré', function () {
    expect($this->machine->fresh()->global_success_rate)->toBe(88.7);
});

test('un cycle seul : son taux est celui des moyennes', function () {
    $seul = Incubation::where('code_incubation', 'INC-COUVEE')->first();

    expect($seul->hatchability_rate)->toBe(90.0)
        ->and(Incubation::tauxPonderes([$seul])['eclosion'])->toBe(90.0);
});
