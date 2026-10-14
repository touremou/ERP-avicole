<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\FeedPurchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE PRIX DE L'ALIMENT NE MÉLANGE PAS LES FLACONS.
 *
 * Le rapport mensuel tirait son prix de repli au kilo de TOUS les achats du
 * lot — un flacon de vaccin comptait comme un kilo d'aliment — et convertissait
 * les sacs au poids du réglage en ignorant le poids propre de l'achat. Un sac
 * saisi « sac » (minuscule) entrait en stock au kilo mais restait un « sac » de
 * 1 kg pour l'achat lui-même. Et la densité d'un bâtiment ne voyait que son
 * premier lot.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

test('un sac reste un sac quelle que soit la casse', function () {
    $achat = new FeedPurchase(['quantity' => 2, 'unit' => 'sac', 'metadata' => ['bag_weight' => 25]]);

    expect($achat->normalized_quantity)->toBe(50.0);
});

test('la densité d’un bâtiment compte tous ses lots', function () {
    $batiment = Building::factory()->create(['farm_id' => $this->farm->id, 'type' => 'chair', 'surface' => 100, 'capacity' => 2000]);
    foreach ([500, 300] as $n) {
        Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $batiment->id, 'status' => 'Actif',
            'initial_quantity' => $n, 'current_quantity' => $n]);
    }

    expect($batiment->current_density)->toBe(8.0);
});

test('le prix de repli du rapport mensuel ne compte que l’aliment', function () {
    $lot = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'arrival_date' => now()->startOfMonth()->toDateString()]);
    FeedPurchase::withoutEvents(function () use ($lot) {
        FeedPurchase::forceCreate(['farm_id' => $this->farm->id, 'batch_id' => $lot->id, 'feed_type' => 'Démarrage',
            'quantity' => 1, 'unit' => 'Sac', 'unit_price' => 25000, 'total_price' => 25000,
            'purchase_date' => now()->toDateString(), 'metadata' => ['conso_type' => 'Aliment', 'bag_weight' => 50]]);
        FeedPurchase::forceCreate(['farm_id' => $this->farm->id, 'batch_id' => $lot->id, 'feed_type' => 'Vaccin',
            'quantity' => 10, 'unit' => 'Flacon', 'unit_price' => 50000, 'total_price' => 500000,
            'purchase_date' => now()->toDateString(), 'metadata' => ['conso_type' => 'Médicament']]);
    });
    \Illuminate\Support\Facades\DB::table('daily_checks')->insert([
        'farm_id' => $this->farm->id, 'batch_id' => $lot->id, 'check_date' => now()->toDateString(),
        'feed_consumed' => 10, 'feed_type' => 'Sans article', 'mortality' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $mois = $this->get(route('reports.monthly', ['year' => now()->year]))->assertOk()->viewData('monthlyData');

    // 25 000 le sac de 50 kg = 500 /kg, et non (25 000 + 500 000) / 60 « kg ».
    expect($mois[now()->month][$lot->id]['feed_cost'])->toEqual(5000);
});
