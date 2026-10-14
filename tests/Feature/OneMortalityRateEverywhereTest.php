<?php

use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN LOT, UN TAUX DE MORTALITÉ.
 *
 * La fiche du lot compte toutes les pertes — transport, troupeau, infirmerie —
 * sur l'effectif livré. Ailleurs, le même taux avait sa propre recette :
 *   • tableau de bord (et la viabilité qui en découle) : sans l'infirmerie ;
 *   • liste des bâtiments : sans l'infirmerie, rapporté aux seuls vivants reçus ;
 *   • fiche fournisseur : sans l'infirmerie NI les morts au transport — le
 *     critère même qui juge une livraison ;
 *   • filtre « lots critiques » : sans l'infirmerie, quand l'alerte la compte ;
 *   • formulaire de clôture : « Pertes » = initial − actuel, ventes comprises.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    // 1 000 livrés : 960 vivants + 40 morts au transport ; puis 20 morts au
    // troupeau, 10 en infirmerie, et 100 vendus.
    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'provider_id' => $this->provider->id,
        'initial_quantity' => 960, 'qty_dead' => 40, 'current_quantity' => 830,
        'arrival_date' => now()->subDays(20)->toDateString(),
    ]);
    DB::table('daily_checks')->insert([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id, 'check_date' => now()->subDay()->toDateString(),
        'mortality' => 20, 'mortality_infirmary' => 10, 'feed_consumed' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

test('le taux du groupe est celui de la fiche : 70 morts sur 1 000 livrés', function () {
    expect($this->lot->mortality_rate)->toBe(7.0)
        ->and(Batch::tauxMortaliteDe([$this->lot]))->toBe(7.0);
});

test('le tableau de bord donne le taux de la fiche', function () {
    expect((float) $this->get(route('dashboard'))->assertOk()->viewData('globalMortalityRate'))->toBe(7.0);
});

test('bâtiments et fournisseur affichent le taux de la fiche', function () {
    $this->get(route('buildings.index'))->assertOk()->assertSee('7.0%');
    $this->get(route('providers.show', $this->provider->id))->assertOk()->assertSee('7.0%');
});

test('le lot « critique » est celui que l’alerte signale — infirmerie comprise', function () {
    expect(Batch::critical(6.5)->pluck('id')->all())->toBe([$this->lot->id]);
});

test('les pertes de la clôture sont des morts, pas des ventes', function () {
    $this->get(route('batches.close_form', $this->lot))->assertOk()->assertViewHas('totalMortality', 70);
});
