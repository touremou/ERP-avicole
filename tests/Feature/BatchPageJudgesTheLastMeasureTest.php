<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Services\BatchAdvisorService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA FICHE DU LOT ET SON CONSEILLER JUGENT LA MÊME MESURE, CONTRE LA MÊME CIBLE.
 *
 * Poids : la tuile et l'alerte « retard de croissance » lisaient le DERNIER
 * POINTAGE — un jour sans pesée, la tuile affichait 0 g et le conseiller se
 * taisait — et comparaient la pesée à la cible d'AUJOURD'HUI. Leurs seuils
 * différaient aussi (tuile 95 / 85 %, conseiller 90 / 80 %).
 *
 * Uniformité : la tuile jugeait la dernière mesure contre 80 % fixe ; le
 * conseiller, la cible du guide contre le seul pointage du jour.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(Database\Seeders\ProductionNormSeeder::class);
    $this->actingAs($this->managerUser);

    // ISA Brown en semaine 6, pesée il y a 7 jours ; le pointage du jour,
    // lui, ne porte ni poids ni uniformité.
    $this->lot = Batch::factory()->create([
        'production_type_id' => ProductionType::resolveOrCreate('ponte', null)->id,
        'model_name' => 'ISA Brown', 'arrival_date' => now()->subDays(38),
        'initial_quantity' => 500, 'current_quantity' => 500, 'qty_alive' => 500,
    ]);
    $this->datePesee = today()->subDays(7);
    $this->cibleAlaPesee = (float) ProductionNorm::cibleA($this->lot, $this->lot->semaineDuCycle($this->datePesee))['weight'];

    DailyCheck::factory()->create([
        'batch_id' => $this->lot->id, 'check_date' => today()->toDateString(), 'mortality' => 0,
        'avg_weight' => null, 'uniformity_pct' => null,
    ]);
});

function peseeA(Batch $lot, \Carbon\Carbon $date, float $grammes, ?float $uniformite = null): void
{
    DailyCheck::factory()->create([
        'batch_id' => $lot->id, 'check_date' => $date->toDateString(), 'mortality' => 0,
        'avg_weight' => $grammes / 1000, 'uniformity_pct' => $uniformite,
    ]);
}

function titresConseiller(Batch $lot): \Illuminate\Support\Collection
{
    return collect(app(BatchAdvisorService::class)->advisories($lot->fresh()->load('dailyChecks')));
}

test('la tuile poids montre la dernière pesée, pas 0 g, et le conseiller la juge', function () {
    $poids = round($this->cibleAlaPesee * 0.85);   // 85 % de la cible de SA semaine
    peseeA($this->lot, $this->datePesee, $poids);

    $pesee = $this->lot->fresh()->dernierePesee();
    expect($pesee['poids_g'])->toEqual($poids)
        ->and(Batch::niveauPoids($pesee['pct']))->toBe('moyen');

    $this->get(route('batches.show', $this->lot))->assertOk()
        ->assertSee(number_format($poids, 0) . 'g', false)
        ->assertSee(__('Pesée du') . ' ' . $this->datePesee->format('d/m'), false);

    $retard = titresConseiller($this->lot)->firstWhere('title', 'Retard de croissance');
    expect($retard)->not->toBeNull()
        ->and($retard['severity'])->toBe('attention');
});

test('à 92 % de la cible, ni la tuile ni le conseiller ne signalent de retard', function () {
    peseeA($this->lot, $this->datePesee, round($this->cibleAlaPesee * 0.92));

    expect(Batch::niveauPoids($this->lot->fresh()->dernierePesee()['pct']))->toBe('bon')
        ->and(titresConseiller($this->lot)->pluck('title'))->not->toContain('Retard de croissance');
});

test('une uniformité mesurée la semaine dernière sous la cible du guide alerte, même sans pesée ce jour', function () {
    peseeA($this->lot, $this->datePesee, round($this->cibleAlaPesee), uniformite: 65);

    $mesure = $this->lot->fresh()->derniereUniformite();
    expect($mesure['pct'])->toEqual(65.0)->and($mesure['cible'])->toEqual(80.0);

    $alerte = titresConseiller($this->lot)->firstWhere('title', 'Uniformité du lot insuffisante');
    expect($alerte)->not->toBeNull()->and($alerte['severity'])->toBe('critique');

    $this->get(route('batches.show', $this->lot))->assertOk()->assertSee('/ ≥ 80 %', false);
});
