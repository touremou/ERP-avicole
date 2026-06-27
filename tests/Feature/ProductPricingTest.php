<?php

use App\Models\Client;
use App\Models\Product;
use App\Models\SalePriceList;
use App\Models\SalePriceListItem;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function product(array $attrs = []): Product
{
    return Product::create(array_merge([
        'farm_id' => session('current_farm_id'), 'name' => 'Œuf L', 'product_type' => 'oeufs',
        'unit' => 'alveole', 'base_price' => 3000, 'is_active' => true,
    ], $attrs));
}

// ─── Cascade de prix par article ───────────────────────────────────────────────

test('le prix par article du tarif est prioritaire', function () {
    $p = product();
    $list = SalePriceList::create(['farm_id' => session('current_farm_id'), 'name' => 'Grossiste', 'is_default' => true]);
    // prix catégorie 2500, mais prix article 2300 → l'article gagne
    SalePriceListItem::create(['sale_price_list_id' => $list->id, 'product_type' => 'oeufs', 'unit_price' => 2500]);
    SalePriceListItem::create(['sale_price_list_id' => $list->id, 'product_id' => $p->id, 'product_type' => 'oeufs', 'unit_price' => 2300]);

    expect(SalePriceList::priceForProduct(null, $p))->toBe(2300.0);
});

test('à défaut de prix article, le prix catégorie s\'applique', function () {
    $p = product();
    $list = SalePriceList::create(['farm_id' => session('current_farm_id'), 'name' => 'Détail', 'is_default' => true]);
    SalePriceListItem::create(['sale_price_list_id' => $list->id, 'product_type' => 'oeufs', 'unit_price' => 3200]);

    expect(SalePriceList::priceForProduct(null, $p))->toBe(3200.0);
});

test('sans aucun tarif, le prix de base de l\'article est utilisé', function () {
    $p = product(['base_price' => 2800]);

    expect(SalePriceList::priceForProduct(null, $p))->toBe(2800.0);
});

// ─── Endpoint de suggestion par article ────────────────────────────────────────

test('suggest-price renvoie le prix de l\'article pour le client', function () {
    $p = product(['base_price' => 3000]);
    $list = SalePriceList::create(['farm_id' => session('current_farm_id'), 'name' => 'Gros', 'is_default' => false]);
    SalePriceListItem::create(['sale_price_list_id' => $list->id, 'product_id' => $p->id, 'product_type' => 'oeufs', 'unit_price' => 2400]);
    $client = Client::create(['farm_id' => session('current_farm_id'), 'client_id' => 'CLI-P', 'name' => 'Gros', 'type' => 'entreprise', 'category' => 'grossiste', 'price_list_id' => $list->id]);

    $this->actingAs($this->adminUser)
        ->getJson(route('sales.suggest-price', ['client_id' => $client->id, 'product_id' => $p->id]))
        ->assertOk()
        ->assertJson(['price' => 2400]);
});

// ─── Gestion : prix par article dans un tarif ──────────────────────────────────

test('on peut fixer un prix par article dans un tarif', function () {
    $p = product();
    $list = SalePriceList::create(['farm_id' => session('current_farm_id'), 'name' => 'Demi-gros', 'is_default' => false]);

    $this->actingAs($this->adminUser)
        ->put(route('sales.price-lists.update', $list->id), ['article_prices' => [$p->id => 2600]])
        ->assertRedirect();

    expect((float) $list->items()->where('product_id', $p->id)->value('unit_price'))->toBe(2600.0);
});

// ─── Le formulaire de vente reçoit le catalogue ─────────────────────────────────

test('le formulaire de vente expose le catalogue d\'articles', function () {
    product(['name' => 'Poulet Braisé']);

    $this->actingAs($this->adminUser)
        ->get(route('sales.create'))
        ->assertOk()
        ->assertViewHas('catalog', fn ($c) => $c->contains(fn ($a) => $a['name'] === 'Poulet Braisé'));
});
