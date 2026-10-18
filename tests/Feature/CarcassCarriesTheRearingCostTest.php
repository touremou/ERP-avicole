<?php

use App\Models\Batch;
use App\Models\FinishedProduct;
use App\Models\HealthCheck;
use App\Models\SlaughterOrder;
use App\Services\SlaughterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA CARCASSE PORTE CE QU'A COÛTÉ L'ANIMAL, PAS LE SEUL PRIX DU POUSSIN.
 *
 * Le coût matière d'un abattage interne ne retenait que l'acquisition : une
 * carcasse de poulet nourri 42 jours entrait en stock au prix du poussin, et
 * la marge de l'abattoir en était gonflée d'autant. Le lot, lui, gardait ces
 * coûts sans aucune recette : les deux marges comptaient l'acquisition deux
 * fois. Désormais (choix laissé par l'exploitation : la meilleure approche) :
 *   • le lot CÈDE ses sujets à leur coût de revient (acquisition + élevage),
 *     figé sur l'ordre à l'abattage ;
 *   • la carcasse, la découpe et le dossier de lot lisent ce coût ;
 *   • le lot compte la cession comme un produit : ni gain ni perte sur les
 *     sujets cédés, la marge de la filière se lit à l'abattoir.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    // 100 poussins à 500 = 50 000 ; 200 kg d'aliment à 400 = 80 000 ;
    // 10 000 d'actes vétérinaires → 140 000, soit 1 400 par sujet.
    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif', 'type' => 'chair',
        'initial_quantity' => 100, 'current_quantity' => 100, 'qty_dead' => 0,
        'buy_price_per_unit' => 500, 'total_acquisition_cost' => 50000, 'additional_costs' => 0,
        'arrival_date' => now()->subDays(42)->toDateString(),
    ]);
    DB::table('daily_checks')->insert([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id, 'check_date' => now()->subDays(5)->toDateString(),
        'feed_consumed' => 200, 'feed_unit_cost' => 400, 'mortality' => 0, 'mortality_infirmary' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    HealthCheck::create(['farm_id' => $this->farm->id, 'batch_id' => $this->lot->id, 'intervention_date' => now()->subDays(30)->toDateString(),
        'type' => 'Vaccin', 'product_name' => 'Newcastle', 'mode_administration' => 'Eau', 'cost' => 10000]);
});

function ordreAbattageDuLot(Batch $lot, int $quantite, int $demandeur): SlaughterOrder
{
    return SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $lot->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => $quantite,
        'status' => 'planifie', 'requested_by' => $demandeur,
    ]);
}

test('le coût de revient par sujet réunit acquisition et élevage', function () {
    expect($this->lot->coutDeRevientParSujet())->toEqual(1400.0);
});

test('la carcasse entre en stock au coût de revient, figé sur l’ordre ; le lot ne compte plus ces coûts deux fois', function () {
    $ordre = ordreAbattageDuLot($this->lot, 100, $this->adminUser->id);

    app(SlaughterService::class)->executeSlaughter($ordre, [
        'actual_quantity' => 100, 'total_live_weight_kg' => 220,
        'total_carcass_weight_kg' => 150, 'execution_date' => now()->toDateString(),
    ]);

    $ordre->refresh();
    expect((float) $ordre->material_cost)->toEqual(140000.0)
        ->and($ordre->coutMatiere())->toEqual(140000.0)
        ->and($ordre->economicSummary()['cost'])->toEqual(140000.0)
        ->and($ordre->economicSummary()['cost_label'])->toBe('Coût de revient du lot');

    // 140 000 / 150 kg de carcasse, et non 50 000 / 150 kg.
    $carcasse = FinishedProduct::where('product_type', 'entier_frais')->firstOrFail();
    expect(round((float) $carcasse->unit_cost, 2))->toEqual(round(140000 / 150, 2));

    // Le lot a cédé ses sujets à leur coût : ni gain ni perte sur eux.
    $lot = $this->lot->fresh();
    expect($lot->cessions_abattoir)->toEqual(140000.0)
        ->and(round($lot->net_margin, 2))->toEqual(0.0);
});

test('un ordre exécuté avant garde son ancien coût matière (acquisition seule)', function () {
    $ancien = ordreAbattageDuLot($this->lot, 40, $this->adminUser->id);
    $ancien->forceFill(['status' => 'termine', 'actual_quantity' => 40])->save();

    expect($ancien->fresh()->material_cost)->toBeNull()
        ->and($ancien->fresh()->coutMatiere())->toEqual(20000.0)   // 40 × 500
        ->and($this->lot->fresh()->cessions_abattoir)->toEqual(0.0);
});
