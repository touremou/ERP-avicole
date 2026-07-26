<?php

use App\Actions\Crop\RecordCropTransformation;
use App\Actions\Crop\RecordHarvest;
use App\Models\CropCycle;
use App\Models\CropRecipe;
use App\Models\Harvest;
use App\Models\Plot;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * T1 — La récolte qui n'est PAS vendue.
 *
 * Scénario réel : gombo récolté en saison des pluies, prix du marché au plancher.
 * On sèche pour vendre quatre mois plus tard. Trois choses doivent être vraies :
 *
 *  1. le cycle n'inscrit AUCUN revenu pour ce qui n'a pas été encaissé ;
 *  2. le coût des kg conservés quitte le cycle avec la matière (COGS), sinon la
 *     marge du mois de récolte est catastrophique et celle du mois de vente est
 *     un profit sans coût ;
 *  3. le produit fini est valorisé au COÛT DE REVIENT, jamais au prix de vente
 *     visé — sans quoi la marge sortirait à zéro le jour de l'encaissement.
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->managerUser);
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);
});

/** Cycle de gombo : 1 000 000 GNF engagés, prêt à récolter. */
function gomboCycle(float $cost = 1_000_000): CropCycle
{
    $plot = Plot::create([
        'code' => 'P-' . Str::upper(Str::random(4)), 'name' => 'Parcelle gombo',
        'area_ha' => 0.5, 'status' => Plot::STATUS_EN_CULTURE,
    ]);

    return CropCycle::create([
        'plot_id' => $plot->id,
        'code' => 'GOM-' . Str::upper(Str::random(4)),
        'crop_name' => 'Gombo',
        'area_used_ha' => 0.5,
        'planting_date' => now()->subDays(60)->toDateString(),
        'status' => CropCycle::STATUS_EN_COURS,
        'total_acquisition_cost' => $cost,
        'additional_costs' => 0,
    ]);
}

// ───────────────── REVENU : seul l'encaissé compte ─────────────────

test('une récolte VENDUE inscrit son revenu au cycle', function () {
    $cycle = gomboCycle();

    app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(),
        'quantity' => 200, 'unit' => 'kg',
        'destination' => Harvest::DEST_VENTE,
        'unit_price' => 5000,
    ]);

    expect((float) $cycle->fresh()->total_revenue)->toBe(1_000_000.0);
});

test('une récolte À TRANSFORMER n’inscrit AUCUN revenu, même avec un prix saisi', function () {
    $cycle = gomboCycle();

    $harvest = app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(),
        'quantity' => 200, 'unit' => 'kg',
        'destination' => Harvest::DEST_TRANSFORMATION,
        // Prix du jour, bas : c'est exactement ce qu'il ne faut PAS compter.
        'unit_price' => 2000,
    ]);

    // Le prix est écarté à la source : conservé, il serait tôt ou tard resommé.
    expect($harvest->unit_price)->toBeNull();
    expect((float) $cycle->fresh()->total_revenue)->toBe(0.0);
});

test('une récolte STOCKÉE pour vendre plus tard n’inscrit aucun revenu', function () {
    $cycle = gomboCycle();

    app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(),
        'quantity' => 150, 'unit' => 'kg',
        'destination' => Harvest::DEST_STOCKAGE,
    ]);

    expect((float) $cycle->fresh()->total_revenue)->toBe(0.0);
});

test('changer la destination d’une récolte recalcule le revenu du cycle', function () {
    $cycle = gomboCycle();

    $harvest = app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(),
        'quantity' => 100, 'unit' => 'kg',
        'destination' => Harvest::DEST_VENTE,
        'unit_price' => 4000,
    ]);
    expect((float) $cycle->fresh()->total_revenue)->toBe(400_000.0);

    // Finalement on la sèche : le revenu doit disparaître.
    $harvest->update(['destination' => Harvest::DEST_TRANSFORMATION]);
    expect((float) $cycle->fresh()->total_revenue)->toBe(0.0);
});

// ───────────────── COÛT : il suit la matière ─────────────────

test('le coût des kg conservés quitte le cycle avec la matière (COGS)', function () {
    // 1 000 000 GNF pour 1 000 kg → 1 000 GNF/kg.
    $cycle = gomboCycle(1_000_000);

    // 200 kg vendus à 5 000, 800 kg séchés.
    app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 200, 'unit' => 'kg',
        'destination' => Harvest::DEST_VENTE, 'unit_price' => 5000,
    ]);
    app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 800, 'unit' => 'kg',
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);

    $cycle = $cycle->fresh();

    expect($cycle->productionCostPerKg())->toBe(1000.0);

    $held = $cycle->heldValorisation();
    expect($held['kg'])->toBe(800.0);
    expect($held['value'])->toBe(800_000.0);

    // COGS = coût des 200 kg vendus seulement.
    expect($cycle->costOfGoodsSold())->toBe(200_000.0);
    // Marge réalisée = 1 000 000 encaissés − 200 000 de coût des ventes.
    expect($cycle->net_margin)->toBe(800_000.0);
});

test('sans récolte conservée, la marge reste exactement l’ancienne formule', function () {
    // Non-régression : tout l'historique est en destination « vente ».
    $cycle = gomboCycle(300_000);

    app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 100, 'unit' => 'kg',
        'destination' => Harvest::DEST_VENTE, 'unit_price' => 4000,
    ]);

    $cycle = $cycle->fresh();
    expect($cycle->heldValorisation()['value'])->toBe(0.0);
    expect($cycle->costOfGoodsSold())->toBe(300_000.0);
    expect($cycle->net_margin)->toBe(100_000.0); // 400 000 − 300 000
});

// ───────────────── PESÉE : on ne conserve pas ce qu'on n'a pas pesé ─────────────────

test('une récolte conservée sans pesée en kg est refusée', function () {
    $cycle = gomboCycle();

    expect(fn () => app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(),
        'quantity' => 12, 'unit' => 'panier',   // aucun poids : invalorisable
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(Harvest::count())->toBe(0);
});

test('une récolte conservée entre en stock sans qu’on ait à le demander', function () {
    $cycle = gomboCycle(1_000_000);

    $harvest = app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 500, 'unit' => 'kg',
        'destination' => Harvest::DEST_TRANSFORMATION,
        // sync_to_stock volontairement ABSENT : la matière doit exister quand même.
    ]);

    expect($harvest->synced_to_stock)->toBeTrue();

    $stock = Stock::where('item_name', 'Gombo')->where('category', Stock::CAT_RECOLTES)->first();
    expect($stock)->not->toBeNull();
    expect((float) $stock->current_quantity)->toBe(500.0);
    // Valorisé au COÛT de production (1 000 000 / 500 kg), pas à un prix de vente.
    expect((float) $stock->last_unit_price)->toBe(2000.0);
});

// ───────────────── TRANSFORMATION : le coût de revient ─────────────────

test('le produit fini est valorisé au COÛT DE REVIENT, pas au prix de vente visé', function () {
    $cycle = gomboCycle(1_000_000);

    // 1 000 kg récoltés → 1 000 GNF/kg de coût de production.
    $harvest = app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 1000, 'unit' => 'kg',
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);

    // Séchage : 1 000 kg frais → 100 kg secs, 200 000 GNF d'opération.
    $transformation = app(RecordCropTransformation::class)->execute([
        'harvest_id' => $harvest->id,
        'input_product' => 'Gombo frais',
        'output_product' => 'Gombo séché',
        'transformation_type' => 'sechage',
        'input_quantity' => 1000, 'input_unit' => 'kg',
        'output_quantity' => 100, 'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
        'production_cost' => 200_000,
        // Prix de vente ESPÉRÉ, volontairement très supérieur au coût.
        'output_unit_price' => 25_000,
        'consumed_from_stock' => true,
        'input_stock_item' => 'Gombo',
        'synced_to_stock' => true,
        'output_stock_item' => 'Gombo séché',
    ]);

    // Matière : 1 000 kg × 1 000 = 1 000 000. Total : 1 200 000 pour 100 kg.
    expect((float) $transformation->input_cost)->toBe(1_000_000.0);
    expect((float) $transformation->output_unit_cost)->toBe(12_000.0);
    expect($transformation->total_cost)->toBe(1_200_000.0);

    // Le stock du produit fini porte le COÛT (12 000), PAS le prix visé (25 000).
    $finished = Stock::where('item_name', 'Gombo séché')
        ->where('category', Stock::CAT_PRODUITS_FINIS)->first();
    expect((float) $finished->last_unit_price)->toBe(12_000.0);

    // La marge attendue est donc réelle et positive, pas écrasée à zéro.
    expect($transformation->expected_margin)->toBe(1_300_000.0); // 2 500 000 − 1 200 000
});

test('un séchage qui détruit de la valeur affiche une marge attendue négative', function () {
    $cycle = gomboCycle(1_000_000);

    $harvest = app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 1000, 'unit' => 'kg',
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);

    // Même séchage, mais le marché du sec ne monte qu'à 8 000/kg : ×10 de coût
    // au kilo contre ×8 de prix → l'opération perd de l'argent. Le système doit
    // le DIRE, c'est tout l'intérêt de porter le coût de revient.
    $transformation = app(RecordCropTransformation::class)->execute([
        'harvest_id' => $harvest->id,
        'input_product' => 'Gombo frais', 'output_product' => 'Gombo séché',
        'transformation_type' => 'sechage',
        'input_quantity' => 1000, 'input_unit' => 'kg',
        'output_quantity' => 100, 'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
        'production_cost' => 200_000,
        'output_unit_price' => 8_000,
    ]);

    expect($transformation->expected_margin)->toBe(-400_000.0); // 800 000 − 1 200 000
    expect($transformation->expected_margin_percent)->toBeLessThan(0);
});

test('sans source de coût, le coût matière est AVOUÉ dans les notes, pas inventé', function () {
    $transformation = app(RecordCropTransformation::class)->execute([
        'input_product' => 'Mangue', 'output_product' => 'Mangue séchée',
        'transformation_type' => 'sechage',
        'input_quantity' => 50, 'input_unit' => 'kg',
        'output_quantity' => 6, 'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
        'production_cost' => 30_000,
    ]);

    expect((float) $transformation->input_cost)->toBe(0.0);
    // Le coût de revient ne porte alors que l'opération — et l'écran le dit.
    expect((float) $transformation->output_unit_cost)->toBe(5_000.0);
    expect($transformation->notes)->toContain('Coût matière non déterminé');
});

test('la traçabilité remonte du lot transformé à sa récolte', function () {
    $cycle = gomboCycle();
    $harvest = app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 300, 'unit' => 'kg',
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);

    $transformation = app(RecordCropTransformation::class)->execute([
        'harvest_id' => $harvest->id,
        'input_product' => 'Gombo frais', 'output_product' => 'Gombo séché',
        'transformation_type' => 'sechage',
        'input_quantity' => 300, 'input_unit' => 'kg',
        'output_quantity' => 30, 'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
    ]);

    expect($transformation->harvest_id)->toBe($harvest->id);
    // Le cycle est déduit de la récolte : pas de double saisie.
    expect($transformation->crop_cycle_id)->toBe($cycle->id);
    expect($harvest->fresh()->transformations)->toHaveCount(1);
});

// ───────────────── MOBILE : l'atelier hors réseau ─────────────────

test('mobile : crop_transformation.create porte le coût de revient au push', function () {
    $cycle = gomboCycle(600_000);
    $harvest = app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 600, 'unit' => 'kg',
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);

    $payload = [
        'uuid' => (string) Str::uuid(),
        'harvest_id' => $harvest->id,
        'input_product' => 'Gombo frais', 'output_product' => 'Gombo séché',
        'transformation_type' => 'sechage',
        'input_quantity' => 600, 'input_unit' => 'kg',
        'output_quantity' => 60, 'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
        'production_cost' => 60_000,
        'synced_to_stock' => true, 'output_stock_item' => 'Gombo séché',
    ];

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'crop_transformation.create', 'payload' => $payload,
    ]]]);

    $res->assertOk();
    expect($res->json('results.0.status'))->toBe('success');

    $transformation = \App\Models\CropTransformation::first();
    // 600 kg × 1 000 = 600 000 de matière + 60 000 d'opération = 660 000 / 60 kg.
    expect((float) $transformation->output_unit_cost)->toBe(11_000.0);
    expect((float) $transformation->yield_percent)->toBe(10.0);

    // Rejeu → already_synced, pas un second lot.
    $res2 = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'crop_transformation.create', 'payload' => $payload,
    ]]]);
    expect($res2->json('results.0.status'))->toBe('already_synced');
    expect(\App\Models\CropTransformation::count())->toBe(1);
});

test('mobile : engager deux fois la même récolte est refusé (matière déjà prise)', function () {
    $cycle = gomboCycle();
    $harvest = app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 400, 'unit' => 'kg',
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);

    $base = [
        'harvest_id' => $harvest->id,
        'input_product' => 'Gombo frais', 'output_product' => 'Gombo séché',
        'transformation_type' => 'sechage',
        'input_quantity' => 400, 'input_unit' => 'kg',
        'output_quantity' => 40, 'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
    ];

    $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'crop_transformation.create',
        'payload' => $base + ['uuid' => (string) Str::uuid()],
    ]]])->assertOk();

    // Deuxième lot, uuid DIFFÉRENT (donc pas un rejeu) sur la même récolte.
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'crop_transformation.create',
        'payload' => $base + ['uuid' => (string) Str::uuid()],
    ]]]);

    expect($res->json('results.0.status'))->toBe('conflict');
    expect(\App\Models\CropTransformation::count())->toBe(1);
});

test('mobile : une sortie supérieure à l’entrée est refusée sans rejeu', function () {
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'crop_transformation.create',
        'payload' => [
            'uuid' => (string) Str::uuid(),
            'input_product' => 'Maïs', 'output_product' => 'Farine',
            'transformation_type' => 'mouture',
            'input_quantity' => 100, 'input_unit' => 'kg',
            'output_quantity' => 300, 'output_unit' => 'kg',   // impossible
            'production_date' => now()->toDateString(),
        ],
    ]]]);

    // ValidationException métier → conflict (non rejouable), pas une erreur 500.
    expect($res->json('results.0.status'))->toBe('conflict');
    expect(\App\Models\CropTransformation::count())->toBe(0);
});

test('mobile : la récolte descendue au terrain porte sa destination', function () {
    $cycle = gomboCycle();
    app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 250, 'unit' => 'kg',
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);
    // Une récolte vendue ne doit PAS apparaître dans la liste de l'atelier.
    app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 50, 'unit' => 'kg',
        'destination' => Harvest::DEST_VENTE, 'unit_price' => 3000,
    ]);

    $res = $this->getJson('/api/v1/sync/pull');
    $res->assertOk();

    $pending = $res->json('entities.pending_harvests.upserts');
    expect($pending)->toHaveCount(1);
    expect($pending[0]['destination'])->toBe('transformation');
    expect((float) $pending[0]['net_weight_kg'])->toBe(250.0);
});

test('pull : une récolte déjà transformée quitte la liste de travail de l’atelier', function () {
    $cycle = gomboCycle();
    $harvest = app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 250, 'unit' => 'kg',
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);

    expect($this->getJson('/api/v1/sync/pull')->json('entities.pending_harvests.upserts'))
        ->toHaveCount(1);

    app(RecordCropTransformation::class)->execute([
        'harvest_id' => $harvest->id,
        'input_product' => 'Gombo frais', 'output_product' => 'Gombo séché',
        'transformation_type' => 'sechage',
        'input_quantity' => 250, 'input_unit' => 'kg',
        'output_quantity' => 25, 'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
    ]);

    // Sortie du périmètre SANS tombstone → d'où l'envoi intégral (`full`), qui
    // permet au client de remplacer sa liste au lieu d'accumuler.
    expect($this->getJson('/api/v1/sync/pull')->json('entities.pending_harvests.upserts'))
        ->toHaveCount(0);
});

test('pull : même en delta, la liste de l’atelier est envoyée en entier', function () {
    $cycle = gomboCycle();
    app(RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->subDays(3)->toDateString(), 'quantity' => 100, 'unit' => 'kg',
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);

    // Pull delta « depuis maintenant » : un référentiel classique renverrait 0…
    // urlencode : le « + » du décalage ISO se décoderait sinon en espace.
    $res = $this->getJson('/api/v1/sync/pull?since=' . urlencode(now()->addMinute()->toIso8601String()));
    $res->assertOk();

    // …mais la liste de travail doit rester complète, sinon l'atelier la perd.
    expect($res->json('entities.pending_harvests.upserts'))->toHaveCount(1);
    expect($res->json('entities.crop_cycles.upserts'))->toHaveCount(0);
});

test('pull : les recettes de transformation descendent avec leur rendement', function () {
    CropRecipe::create([
        'code' => 'SEC-GOM', 'name' => 'Séchage gombo', 'transformation_type' => 'sechage',
        'output_product' => 'Gombo séché', 'output_unit' => 'kg',
        'expected_yield_percent' => 10, 'shelf_life_days' => 180, 'is_active' => true,
    ]);
    CropRecipe::create([
        'code' => 'OLD', 'name' => 'Recette retirée', 'transformation_type' => 'autre',
        'output_product' => 'Produit retiré', 'output_unit' => 'kg',
        'expected_yield_percent' => 50, 'is_active' => false,
    ]);

    $recipes = $this->getJson('/api/v1/sync/pull')->json('entities.crop_recipes.upserts');

    expect($recipes)->toHaveCount(1);
    expect($recipes[0]['name'])->toBe('Séchage gombo');
    expect((float) $recipes[0]['expected_yield_percent'])->toBe(10.0);
});
