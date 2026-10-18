<?php

use App\Models\Batch;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES RECETTES D'UN LOT : UNE RÈGLE, SUR CHAQUE ÉCRAN.
 *
 * La marge du lot ne comptait que la vente de réforme saisie à la clôture :
 * un lot de moutons vendus un à un en cours de cycle clôturait « tous les
 * coûts, aucune recette ». La campagne, elle, ne comptait que les lignes de
 * vente — avant remise, et sans la réforme : sa marge ne valait pas la somme
 * des marges de ses lots. Batch::revenus : réforme + ventes nettes + cessions.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->campagne = Campaign::create([
        'farm_id' => $this->farm->id, 'name' => 'Tabaski', 'type' => 'engraissement', 'status' => 'en_cours',
        'start_date' => today()->subDays(90)->toDateString(), 'target_date' => today()->addDays(10)->toDateString(),
    ]);
    // 10 béliers à 30 000 = 300 000, sans autre coût.
    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'campaign_id' => $this->campagne->id,
        'status' => 'Actif', 'initial_quantity' => 10, 'current_quantity' => 6, 'buy_price_per_unit' => 30000,
        'additional_costs' => 0, 'arrival_date' => today()->subDays(90)->toDateString(),
    ]);

    // 4 béliers vendus 100 000 pièce, remise de 10 % sur la facture : 360 000 nets.
    $client = Client::create(['farm_id' => $this->farm->id, 'client_id' => 'CLI-' . Str::random(6), 'name' => 'Boucherie',
        'type' => 'entreprise', 'category' => 'grossiste', 'status' => 'actif', 'credit_limit' => 0]);
    $vente = Sale::create(['farm_id' => $this->farm->id, 'uuid' => (string) Str::uuid(), 'reference' => 'FA-' . Str::random(6),
        'client_id' => $client->id, 'sale_date' => today()->subDays(5)->toDateString(), 'type' => 'facture_tva', 'status' => 'valide',
        'subtotal' => 400000, 'discount_amount' => 40000, 'total_amount' => 360000, 'paid_amount' => 360000,
        'payment_status' => 'solde', 'user_id' => $this->adminUser->id]);
    SaleItem::create(['sale_id' => $vente->id, 'product_type' => 'animal_vif', 'product_name' => 'Bélier', 'batch_id' => $this->lot->id,
        'quantity' => 4, 'unit' => 'tete', 'unit_price' => 100000, 'total' => 400000]);
});

test('les ventes en cours de cycle sont des recettes du lot, nettes de remise', function () {
    $lot = $this->lot->fresh();

    expect($lot->ventes_du_lot)->toEqual(360000.0)
        ->and($lot->revenus)->toEqual(360000.0)
        ->and(round($lot->net_margin))->toEqual(60000.0);   // 360 000 − 300 000
});

test('la campagne vaut la somme de ses lots', function () {
    $campagne = $this->campagne->fresh();

    expect($campagne->realized_revenue)->toEqual($this->lot->fresh()->revenus)
        ->and(round($campagne->realized_margin))->toEqual(round($this->lot->fresh()->net_margin));
});

test('la clôture compte la réforme ET les ventes déjà faites', function () {
    $cloture = $this->get(route('batches.close_form', $this->lot))->assertOk()->viewData('costs');
    expect($cloture['ventes'])->toEqual(360000);

    app(App\Actions\Batch\CloseBatch::class)->execute($this->lot->fresh(), [
        'actual_sell_price_per_unit' => 90000, 'closing_date' => today()->toDateString(), 'additional_costs' => 0,
    ]);

    $lot = $this->lot->fresh();
    // Réforme 6 × 90 000 = 540 000 ; + 360 000 de ventes ; − 300 000.
    expect((float) $lot->total_revenue)->toEqual(540000.0)
        ->and((float) $lot->margin)->toEqual(600000.0)
        ->and(round($lot->net_margin))->toEqual(600000.0);
});
