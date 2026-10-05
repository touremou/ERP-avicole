<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\PlannedBatch;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Models\Protocol;
use App\Models\Setting;
use App\Models\Species;
use Database\Seeders\ProductionNormSeeder;
use Database\Seeders\SpeciesSeeder;
use Illuminate\Support\Carbon;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * SIGNALÉ PAR L'EXPLOITANT, SUR L'ÉCRAN « PLANIFIER UNE NOUVELLE BANDE » :
 *
 *   « Je change de souche mais l'abattage est toujours à J45 alors que les
 *     souches n'ont pas la même durée de croissance. De plus, il y a la
 *     prophylaxie dinde qui est sélectionnable alors que le sujet est poulet
 *     de chair. »
 *
 * Deux défauts du référentiel, et une même règle à trois portes chacun :
 *
 *   1. La fin d'une bande ne dépendait que du TYPE de production. La souche
 *      porte désormais sa durée (ProductionNorm::cycleDaysFor), lue par la
 *      bande, la planification serveur et l'écran.
 *   2. Un protocole n'avait qu'un TYPE (chair), commun à toutes les espèces.
 *      Il porte désormais son ESPÈCE, déduite de sa souche ; écrans ET serveur
 *      appliquent Protocol::convientA.
 *
 * Sur les VRAIS semeurs (espèces, types, normes) : la leçon de #390.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(SpeciesSeeder::class);
    $this->seed(ProductionNormSeeder::class);
    $this->actingAs($this->adminUser);

    $this->poulet = Species::where('slug', 'poulet')->first();
    $this->dinde = Species::where('slug', 'dinde')->first();
    $this->chairPoulet = ProductionType::where('species_id', $this->poulet->id)->where('slug', 'chair')->first();
    $this->arrivee = Carbon::today()->addDays(10);
});

function planifier(object $test, array $extra = [])
{
    $batiment = Building::factory()->create(['type' => 'chair', 'capacity' => 10_000, 'farm_id' => session('current_farm_id')]);

    return $test->post(route('planning.store'), array_merge([
        'building_id'          => $batiment->id,
        'batch_type'           => 'chair',
        'species_id'           => $test->poulet->id,
        'production_type_id'   => $test->chairPoulet->id,
        'planned_quantity'     => 1000,
        'planned_arrival_date' => $test->arrivee->toDateString(),
    ], $extra));
}

// ─── 1. LA SOUCHE FIXE LA DURÉE ───

test('le poulet local Cou Nu finit à J+112, et non au J+45 du type', function () {
    planifier($this, ['model_name' => 'Poulet local Cou Nu'])->assertSessionHasNoErrors();

    expect(PlannedBatch::latest('id')->first()->planned_end_date->toDateString())
        ->toBe($this->arrivee->copy()->addDays(112)->toDateString());
});

test('une souche sans durée propre suit son type — non-régression', function () {
    planifier($this, ['model_name' => 'Ross 308'])->assertSessionHasNoErrors();

    expect(PlannedBatch::latest('id')->first()->planned_end_date->toDateString())
        ->toBe($this->arrivee->copy()->addDays($this->chairPoulet->cycle_days_default)->toDateString());
});

test('la BANDE applique la même durée que la planification annonce', function () {
    $batiment = Building::factory()->create(['type' => 'chair', 'capacity' => 10_000, 'farm_id' => $this->farm->id]);
    $arrivee = Carbon::today()->subDays(2);

    $bande = Batch::factory()->create([
        'building_id' => $batiment->id, 'production_type_id' => $this->chairPoulet->id,
        'species_id' => $this->poulet->id, 'model_name' => 'Poulet local Cou Nu',
        'arrival_date' => $arrivee, 'birth_date' => null,
    ]);

    expect($bande->expected_end_date->toDateString())->toBe($arrivee->copy()->addDays(112)->toDateString());

    // Changer de souche recalcule la fin.
    $bande->update(['model_name' => 'Ross 308']);

    expect($bande->fresh()->expected_end_date->toDateString())
        ->toBe($arrivee->copy()->addDays($this->chairPoulet->cycle_days_default)->toDateString());
});

test('l’écran porte la durée de chaque souche, pour la lire au changement', function () {
    $html = $this->get(route('planning.create'))->assertOk()->getContent();

    expect(str_contains($html, 'value="Poulet local Cou Nu" data-type="chair" data-species="poulet" data-cycle="112"'))->toBeTrue('durée de souche absente de l’écran');
});

test('la durée réglée par l’exploitant vaut pour TOUTE la souche, et survit au semeur', function () {
    $this->put(route('batches.norms.strain_cycle'), ['model_name' => 'Ross 308', 'cycle_days' => 38])
        ->assertSessionHasNoErrors();

    expect(ProductionNorm::where('model_name', 'Ross 308')->pluck('cycle_days')->unique()->all())->toBe([38]);

    $this->seed(ProductionNormSeeder::class);

    expect(ProductionNorm::cycleDaysFor('Ross 308'))->toBe(38);
});

// ─── 2. LE PROTOCOLE PORTE SON ESPÈCE ───

test('l’espèce d’un protocole se déduit de sa souche, ou de son nom', function () {
    $chair = Protocol::create(['name' => 'Programme chair', 'type' => 'chair', 'strain' => 'Ross 308']);
    $dinde = Protocol::create(['name' => 'Prophylaxie Dinde', 'type' => 'chair', 'strain' => 'B.U.T. 6']);
    $generique = Protocol::create(['name' => 'Désinfection standard', 'type' => 'chair', 'strain' => null]);

    expect($chair->species_id)->toBe($this->poulet->id)
        ->and($dinde->species_id)->toBe($this->dinde->id)
        ->and($generique->species_id)->toBeNull();
});

test('planifier un poulet avec la prophylaxie DINDE est refusé', function () {
    $dinde = Protocol::create(['name' => 'Prophylaxie Dinde', 'type' => 'chair', 'strain' => 'B.U.T. 6']);

    planifier($this, ['protocol_id' => $dinde->id])->assertSessionHasErrors('protocol_id');

    expect(PlannedBatch::count())->toBe(0);
});

test('un protocole du poulet, ou générique, est accepté — non-régression', function () {
    $poulet = Protocol::create(['name' => 'Prophylaxie Poulet de Chair', 'type' => 'chair', 'strain' => 'Ross 308']);
    $generique = Protocol::create(['name' => 'Désinfection standard', 'type' => 'chair']);

    planifier($this, ['protocol_id' => $poulet->id])->assertSessionHasNoErrors();
    planifier($this, ['protocol_id' => $generique->id])->assertSessionHasNoErrors();

    expect(PlannedBatch::count())->toBe(2);
});

test('l’écran de planification marque l’espèce de chaque protocole', function () {
    $dinde = Protocol::create(['name' => 'Prophylaxie Dinde', 'type' => 'chair', 'strain' => 'B.U.T. 6']);

    $html = $this->get(route('planning.create'))->getContent();

    expect(str_contains($html, 'data-species-id="'.$this->dinde->id.'"'))->toBeTrue();
});

// ─── 3. LE SERVEUR LIT LES RÉGLAGES QUE L'ÉCRAN AFFICHE ───

test('vide sanitaire et délai de commande : les réglages, pas des constantes', function () {
    Setting::set('elevage.sanitary_break_days', 14);
    Setting::set('planning.order_lead_days', 30);

    $dates = PlannedBatch::calculateDates('chair', $this->arrivee->copy(), 45);

    // Fin J+45 ; vide du lendemain, 14 jours inclus ; commande à J-30.
    expect($dates['sanitary_void_start']->toDateString())->toBe($this->arrivee->copy()->addDays(46)->toDateString())
        ->and($dates['sanitary_void_end']->toDateString())->toBe($this->arrivee->copy()->addDays(45 + 14)->toDateString())
        ->and($dates['chick_order_deadline']->toDateString())->toBe($this->arrivee->copy()->subDays(30)->toDateString());
});

test('créer une BANDE de poulets avec la prophylaxie dinde est refusé aussi', function () {
    $dinde = Protocol::create(['name' => 'Prophylaxie Dinde', 'type' => 'chair', 'strain' => 'B.U.T. 6']);
    $batiment = Building::factory()->create(['type' => 'chair', 'capacity' => 10_000, 'farm_id' => session('current_farm_id')]);

    $this->post(route('batches.store'), [
        'code' => 'PC-DINDE', 'building_id' => $batiment->id, 'type' => 'chair',
        'species_id' => $this->poulet->id, 'production_type_id' => $this->chairPoulet->id,
        'protocol_id' => $dinde->id, 'arrival_date' => now()->toDateString(),
        'buy_price_per_unit' => 500, 'qty_alive' => 100,
    ])->assertSessionHasErrors('protocol_id');

    expect(Batch::where('code', 'PC-DINDE')->exists())->toBeFalse();
});
