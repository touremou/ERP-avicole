<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Client;
use App\Models\DailyCheck;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Stock;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ÉCRAN ET LE SERVEUR DISENT LA MÊME CHOSE.
 *
 * Famille de défauts relevée par l'audit après #408 (« 21 jours » écrit en dur
 * quand le réglage valait 14) : une valeur affichée, ou un contrôle fait à une
 * porte, que le serveur calcule autrement ailleurs.
 */

beforeEach(fn () => $this->setUpRbac());

// ─── STOCK : LE POIDS DE SAC RÉGLÉ ───

test('« 10 sacs » d’aliment valent 10 × le poids de sac réglé, et non 10 × 50 kg', function () {
    Setting::set('general.feed_bag_weight', 25);

    $this->actingAs($this->adminUser)->post(route('stocks.store'), [
        'item_name' => 'Chair Démarrage', 'category' => Stock::CAT_CONSO, 'unit' => 'Sac',
        'alert_threshold' => 2, 'current_quantity' => 10,
    ])->assertSessionHasNoErrors();

    $stock = Stock::where('item_name', 'Chair Démarrage')->first();

    expect((float) $stock->current_quantity)->toBe(250.0)
        ->and((float) $stock->alert_threshold)->toBe(50.0)
        ->and($stock->unit)->toBe('KG');
});

// ─── VENTES : LE TOTAL PAYABLE ───

function clientACredit(object $t, float $solde, float $plafond): Client
{
    return Client::create([
        'farm_id' => session('current_farm_id'), 'client_id' => 'CLI-'.fake()->unique()->numerify('####'),
        'name' => 'Client crédit', 'type' => 'particulier', 'category' => 'detaillant',
        'status' => 'actif', 'balance' => $solde, 'credit_limit' => $plafond,
    ]);
}

function venteSousPlafond(object $t, Client $client, array $extra = [])
{
    return $t->post(route('sales.store'), array_merge([
        'client_id' => $client->id, 'sale_date' => now()->toDateString(),
        'type' => 'bon_livraison', 'tax_rate' => 0,
        'items' => [[
            'product_type' => 'oeufs', 'product_name' => 'Œufs calibre M',
            'quantity' => 10, 'unit' => 'alveole', 'unit_price' => 10_000,
        ]],
    ], $extra));
}

test('le plafond se contrôle À LA CRÉATION sur le total TTC — celui que la validation contrôlera', function () {
    // 100 000 HT + 18 % = 118 000 TTC ; plafond 110 000 : refusé dès la création.
    $tva = (float) setting('general.tva_rate', 18);
    $this->actingAs($this->adminUser);
    venteSousPlafond($this, clientACredit($this, 0, 110_000), ['type' => 'facture', 'tax_rate' => $tva])
        ->assertSessionHasErrors('client_id');

    expect(Sale::count())->toBe(0);
});

test('une vente REMISÉE sous le plafond n’est plus refusée à tort', function () {
    // 100 000 − 20 % = 80 000 ; plafond 90 000 : acceptée (la somme brute la refusait).
    $this->actingAs($this->adminUser);
    venteSousPlafond($this, clientACredit($this, 0, 90_000), ['discount_type' => 'percent', 'discount_value' => 20])
        ->assertSessionHasNoErrors();

    expect(Sale::count())->toBe(1);
});

test('le total projeté à la création est EXACTEMENT celui que la vente porte', function () {
    Setting::set('ventes.cash_rounding', 1000);
    $donnees = [
        'client_id' => clientACredit($this, 0, 0)->id, 'sale_date' => now()->toDateString(),
        'type' => 'facture', 'tax_rate' => (float) setting('general.tva_rate', 18),
        'discount_type' => 'amount', 'discount_value' => 1_250,
        'delivery_mode' => 'livraison', 'delivery_fee' => 2_300,
        'items' => [['product_type' => 'oeufs', 'product_name' => 'Œufs', 'quantity' => 7, 'unit' => 'alveole', 'unit_price' => 3_175]],
    ];

    $this->actingAs($this->adminUser)->post(route('sales.store'), $donnees)->assertSessionHasNoErrors();

    expect((float) Sale::first()->total_amount)->toBe(Sale::totalProjete($donnees));
});

test('un client créé à la CAISSE reçoit le plafond de crédit par défaut', function () {
    Setting::set('ventes.credit_limit_default', 750_000);

    $this->actingAs($this->adminUser)->postJson(route('pos.clients.store'), ['name' => 'Mamadou Caisse'])->assertOk();

    expect((float) Client::where('name', 'Mamadou Caisse')->value('credit_limit'))->toBe(750_000.0);
});

// ─── MORTALITÉ : LA RÈGLE DE L'ALERTE, À L'ÉCRAN ───

test('la liste colore en rouge exactement les pointages qui déclenchent l’alerte', function () {
    Setting::set('elevage.daily_mortality_alert_pct', 0.5);
    Setting::set('elevage.daily_mortality_alert_min', 3);
    $bande = Batch::factory()->create(['current_quantity' => 1000, 'initial_quantity' => 1000]);

    // 8 morts sur 1 000 = 0,8 % : alerte (≥ 0,5 %), et la liste disait « normal » (< 1 %).
    $pic = new DailyCheck(['batch_id' => $bande->id, 'mortality' => 8, 'check_date' => now()]);
    // 2 morts : sous le minimum absolu, jamais d'alerte.
    $isole = new DailyCheck(['batch_id' => $bande->id, 'mortality' => 2, 'check_date' => now()]);

    expect($pic->depasseLeSeuilDeMortalite($bande))->toBeTrue()
        ->and($isole->depasseLeSeuilDeMortalite($bande))->toBeFalse();

    $source = file_get_contents(resource_path('views/daily-checks/index.blade.php'));
    expect(str_contains($source, '* 0.01'))->toBeFalse('la liste garde son seuil de 1 % en dur');
});

// ─── LIBELLÉS : LE RÉGLAGE, PAS UN NOMBRE EN DUR ───

test('la fiche bâtiment annonce le vide sanitaire RÉGLÉ et la vraie date de désinfection', function () {
    Setting::set('elevage.sanitary_break_days', 21);
    $batiment = Building::factory()->create(['disinfection_started_at' => now()->subDays(3)]);

    $html = $this->actingAs($this->adminUser)->get(route('buildings.show', $batiment))->assertOk()->getContent();

    expect(str_contains($html, 'vide sanitaire de 21 jours'))->toBeTrue()
        ->and(str_contains($html, now()->subDays(3)->format('d/m/Y')))->toBeTrue();
});

test('le terrain reçoit le poids de sac et le seuil de mortalité cumulée réglés', function () {
    Setting::set('general.feed_bag_weight', 25);
    Setting::set('elevage.cumulative_mortality_alert_pct', 3);
    Sanctum::actingAs($this->adminUser);

    $reglages = $this->getJson('/api/v1/auth/me')->assertOk()->json('settings');

    expect((float) $reglages['feed_bag_weight'])->toBe(25.0)
        ->and((float) $reglages['cumulative_mortality_alert_pct'])->toBe(3.0);
});
