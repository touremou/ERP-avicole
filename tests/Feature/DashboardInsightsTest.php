<?php

/**
 * Tests — DashboardInsightsService (enrichissement industriel du dashboard).
 *
 * Couvre les calculs zootechniques (IC/FCR, GMQ, viabilité, coût/kg), la
 * synthèse financière du mois et les séries de tendance.
 */

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Expense;
use App\Models\ProductionType;
use App\Services\DashboardInsightsService;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

function insightsBatch(array $attrs = []): Batch
{
    return Batch::factory()->create(array_merge([
        'production_type_id' => ProductionType::resolveOrCreate('chair', null)->id,
        'initial_quantity'   => 1000,
        'current_quantity'   => 1000,
        'qty_dead'           => 0,
        'status'             => 'Actif',
    ], $attrs));
}

test('technical calcule IC, GMQ et viabilité à partir des pointages', function () {
    $batch = insightsBatch(['current_quantity' => 1000]);

    // Deux pesées espacées de 20 j : 0,5 kg → 2,5 kg ⇒ GMQ = 2000 g / 20 = 100 g/j.
    DailyCheck::factory()->create([
        'batch_id' => $batch->id, 'check_date' => now()->subDays(20)->toDateString(),
        'avg_weight' => 0.5, 'feed_consumed' => 0, 'feed_unit_cost' => 0, 'mortality' => 0,
    ]);
    // Cumul d'aliment : 3000 kg consommés sur la période.
    DailyCheck::factory()->create([
        'batch_id' => $batch->id, 'check_date' => now()->toDateString(),
        'avg_weight' => 2.5, 'feed_consumed' => 3000, 'feed_unit_cost' => 400, 'mortality' => 0,
    ]);

    $reco = (new DashboardInsightsService())->technical(
        Batch::active()->live()->get(),
        2.0 // taux de mortalité global (%)
    );

    // Biomasse = 1000 × 2,5 = 2500 kg ; IC = 3000 / 2500 = 1,2.
    expect($reco['fcr'])->toEqualWithDelta(1.2, 0.001);
    // GMQ = (2,5 − 0,5) × 1000 / 20 = 100 g/j.
    expect($reco['gmq_g'])->toBe(100);
    // Viabilité = 100 − 2 = 98 %.
    expect($reco['viability'])->toEqualWithDelta(98.0, 0.01);
    // Coût aliment / kg vif = (3000 × 400) / 2500 = 480 GNF.
    expect($reco['feed_cost_per_kg'])->toEqualWithDelta(480.0, 0.5);
    expect($reco['has_data'])->toBeTrue();
});

test('financial agrège dépenses validées et marge nette du mois', function () {
    $user = App\Models\User::factory()->create();

    // Dépenses validées du mois (comptées) + une non validée (ignorée).
    Expense::factory()->create([
        'user_id' => $user->id, 'category' => 'carburant', 'amount' => 500000,
        'status' => 'valide', 'expense_date' => now()->toDateString(),
    ]);
    Expense::factory()->create([
        'user_id' => $user->id, 'category' => 'main_oeuvre', 'amount' => 300000,
        'status' => 'valide', 'expense_date' => now()->toDateString(),
    ]);
    Expense::factory()->create([
        'user_id' => $user->id, 'category' => 'divers', 'amount' => 999999,
        'status' => 'en_attente', 'expense_date' => now()->toDateString(),
    ]);

    $fin = (new DashboardInsightsService())->financial(now()->startOfMonth(), now()->endOfMonth());

    // Seules les dépenses validées comptent : 500k + 300k = 800k.
    expect($fin['cost_expenses'])->toEqualWithDelta(800000.0, 0.5);
    // Sans vente ni aliment ce mois : marge = −charges.
    expect($fin['net_margin'])->toEqualWithDelta(-800000.0, 0.5);
    // La plus grosse dépense (carburant) figure en tête du top.
    expect($fin['top_expenses'][0]['amount'])->toEqualWithDelta(500000.0, 0.5);
});

test('trends renvoie des séries alignées sur la fenêtre demandée', function () {
    $batch = insightsBatch();
    DailyCheck::factory()->create([
        'batch_id' => $batch->id, 'check_date' => now()->toDateString(),
        'mortality' => 7, 'feed_consumed' => 120, 'feed_unit_cost' => 0, 'avg_weight' => 1.2,
    ]);

    $trends = (new DashboardInsightsService())->trends([$batch->id], 14);

    expect($trends['labels'])->toHaveCount(14);
    expect($trends['mortality'])->toHaveCount(14);
    // La dernière journée (aujourd'hui) porte la mortalité saisie.
    expect(end($trends['mortality']))->toBe(7);
    expect(end($trends['feed']))->toEqualWithDelta(120.0, 0.1);
});
