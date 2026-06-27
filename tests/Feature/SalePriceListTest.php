<?php

use App\Models\Client;
use App\Models\SalePriceList;
use App\Models\SalePriceListItem;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function priceList(string $name, bool $default, array $prices): SalePriceList
{
    $list = SalePriceList::create(['farm_id' => session('current_farm_id'), 'name' => $name, 'is_default' => $default]);
    foreach ($prices as $type => $price) {
        SalePriceListItem::create(['sale_price_list_id' => $list->id, 'product_type' => $type, 'unit_price' => $price]);
    }
    return $list;
}

// ─── Résolution du prix suggéré ───────────────────────────────────────────────

test('le tarif du client est prioritaire sur le tarif par défaut', function () {
    $detail = priceList('Détail', true, ['oeufs' => 3000]);
    $gros   = priceList('Grossiste', false, ['oeufs' => 2500]);

    $client = Client::create([
        'farm_id' => session('current_farm_id'), 'client_id' => 'CLI-001', 'name' => 'Marché Central',
        'type' => 'entreprise', 'category' => 'grossiste', 'price_list_id' => $gros->id,
    ]);

    expect(SalePriceList::suggestedPrice($client, 'oeufs'))->toBe(2500.0);
});

test('sans tarif client, le tarif par défaut s\'applique', function () {
    priceList('Détail', true, ['oeufs' => 3000]);

    $client = Client::create([
        'farm_id' => session('current_farm_id'), 'client_id' => 'CLI-002', 'name' => 'Client Lambda',
        'type' => 'particulier', 'category' => 'detaillant',
    ]);

    expect(SalePriceList::suggestedPrice($client, 'oeufs'))->toBe(3000.0);
});

test('un type de produit non tarifé renvoie null', function () {
    priceList('Détail', true, ['oeufs' => 3000]);
    $client = Client::create(['farm_id' => session('current_farm_id'), 'client_id' => 'CLI-003', 'name' => 'X', 'type' => 'particulier', 'category' => 'detaillant']);

    expect(SalePriceList::suggestedPrice($client, 'lait'))->toBeNull();
});

// ─── Endpoint de suggestion ───────────────────────────────────────────────────

test('l\'endpoint suggest-price renvoie le prix en JSON', function () {
    $gros = priceList('Grossiste', false, ['carcasse' => 18000]);
    $client = Client::create(['farm_id' => session('current_farm_id'), 'client_id' => 'CLI-004', 'name' => 'Boucherie', 'type' => 'entreprise', 'category' => 'grossiste', 'price_list_id' => $gros->id]);

    $this->actingAs($this->adminUser)
        ->getJson(route('sales.suggest-price', ['client_id' => $client->id, 'product_type' => 'carcasse']))
        ->assertOk()
        ->assertJson(['price' => 18000]);
});

// ─── Gestion des tarifs ───────────────────────────────────────────────────────

test('un responsable commerce peut créer un tarif et fixer ses prix', function () {
    $this->actingAs($this->adminUser)
        ->post(route('sales.price-lists.store'), ['name' => 'Demi-gros', 'is_default' => 0])
        ->assertRedirect();

    $list = SalePriceList::where('name', 'Demi-gros')->first();
    expect($list)->not->toBeNull();

    $this->actingAs($this->adminUser)
        ->put(route('sales.price-lists.update', $list->id), ['prices' => ['oeufs' => 2800, 'lait' => '']])
        ->assertRedirect();

    expect((float) $list->items()->where('product_type', 'oeufs')->value('unit_price'))->toBe(2800.0)
        ->and($list->items()->where('product_type', 'lait')->exists())->toBeFalse();
});

test('une seule liste reste « par défaut »', function () {
    priceList('Ancien défaut', true, []);

    $this->actingAs($this->adminUser)
        ->post(route('sales.price-lists.store'), ['name' => 'Nouveau défaut', 'is_default' => 1])
        ->assertRedirect();

    expect(SalePriceList::where('is_default', true)->count())->toBe(1)
        ->and(SalePriceList::where('is_default', true)->value('name'))->toBe('Nouveau défaut');
});
