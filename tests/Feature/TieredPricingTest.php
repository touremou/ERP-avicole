<?php

use App\Models\Client;
use App\Models\Product;
use App\Models\SalePriceList;
use App\Models\SalePriceListItem;
use App\Models\Stock;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN SEUL PRIX POUR UN ARTICLE ET UN CLIENT.
 *
 * L'écran de vente portait DEUX systèmes de tarification en parallèle :
 *
 *   • le tarif du client (SalePriceList), interrogé par AJAX quand l'article
 *     était choisi au CATALOGUE ou par type libre — cascade article → catégorie
 *     → prix de base, et refus d'écraser un prix saisi à la main ;
 *   • une grille `price_lists` injectée dans la page, lue quand l'article était
 *     choisi dans le STOCK ou parmi les LOTS — sans tenir compte du client, sans
 *     filtre de palier (la colonne `category` était même écartée du JSON), et
 *     écrasant au passage un prix déjà tapé.
 *
 * Le même carton d'œufs partait donc au tarif négocié du grossiste s'il était
 * pris au catalogue, et au premier prix trouvé PAR NOM s'il était pris dans le
 * stock — l'ordre des lignes en base décidait du chiffre d'affaires.
 *
 * Cette grille n'avait ni route, ni écran, ni seeder : elle n'était pas
 * administrable. Elle et son service à trois paliers (jamais appelé ailleurs que
 * par son propre test) sont supprimés ; la table est laissée intacte.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->stock = Stock::create([
        'category' => Stock::CAT_PRODUITS_FINIS, 'item_name' => 'Poulet', 'unit' => 'piece',
        'current_quantity' => 100, 'unit_price' => 4800, 'last_unit_price' => 4800, 'alert_threshold' => 5,
    ]);

    $this->product = Product::create([
        'name' => 'Poulet entier', 'product_type' => 'produits_finis', 'stock_id' => $this->stock->id,
        'unit' => 'piece', 'base_price' => 5000, 'is_active' => true,
    ]);
});

function pricedClient(string $name, ?int $listId = null, string $category = 'detaillant'): Client
{
    return Client::create([
        'farm_id' => session('current_farm_id'), 'client_id' => strtoupper(uniqid('C')),
        'name' => $name, 'type' => 'entreprise', 'category' => $category,
        'price_list_id' => $listId, 'status' => 'actif', 'credit_limit' => 0, 'balance' => 0,
    ]);
}

function tariff(string $name, array $items, bool $default = false): SalePriceList
{
    $list = SalePriceList::create(['name' => $name, 'is_default' => $default]);

    foreach ($items as $item) {
        SalePriceListItem::create(array_merge(['sale_price_list_id' => $list->id], $item));
    }

    return $list;
}

test('le tarif du client l’emporte sur le tarif par défaut', function () {
    tariff('Standard', [['product_type' => 'produits_finis', 'unit_price' => 5000]], default: true);
    $gros = tariff('Grossiste', [['product_type' => 'produits_finis', 'unit_price' => 4000]]);

    expect(SalePriceList::suggestedPrice(pricedClient('Grossiste SA', $gros->id), 'produits_finis'))->toBe(4000.0)
        ->and(SalePriceList::suggestedPrice(pricedClient('Passant'), 'produits_finis'))->toBe(5000.0);
});

test('un tarif supprimé retombe sur le tarif par défaut, pas sur rien', function () {
    tariff('Standard', [['product_type' => 'produits_finis', 'unit_price' => 5000]], default: true);
    $obsolete = tariff('Ancien', [['product_type' => 'produits_finis', 'unit_price' => 3000]]);

    $client = pricedClient('Client orphelin', $obsolete->id);
    $obsolete->items()->delete();
    $obsolete->delete();

    // Sans ce repli, la ligne repartait à zéro : une vente à prix nul.
    expect(SalePriceList::suggestedPrice($client->fresh(), 'produits_finis'))->toBe(5000.0);
});

test('le prix par ARTICLE l’emporte sur le prix par catégorie', function () {
    $list = tariff('Grossiste', [
        ['product_type' => 'produits_finis', 'unit_price' => 4000],
        ['product_id' => $this->product->id, 'product_type' => 'produits_finis', 'unit_price' => 3800],
    ]);

    expect(SalePriceList::priceForProduct(pricedClient('G', $list->id), $this->product))->toBe(3800.0);
});

test('sans aucun tarif, le prix de base de l’article sert de repli', function () {
    expect(SalePriceList::priceForProduct(pricedClient('Passant'), $this->product))->toBe(5000.0);
});

test('le prix dit D’OÙ il vient', function () {
    // C'est ce silence qui a laissé une seconde grille écraser le tarif négocié
    // sans que personne le voie à l'écran.
    $list = tariff('Grossiste', [
        ['product_type' => 'produits_finis', 'unit_price' => 4000],
        ['product_id' => $this->product->id, 'product_type' => 'produits_finis', 'unit_price' => 3800],
    ]);
    $client = pricedClient('G', $list->id);

    expect(SalePriceList::explainPrice($client, $this->product, null))
        ->toMatchArray(['price' => 3800.0, 'source' => 'article']);

    SalePriceListItem::where('product_id', $this->product->id)->delete();
    expect(SalePriceList::explainPrice($client, $this->product, null))
        ->toMatchArray(['price' => 4000.0, 'source' => 'categorie']);

    SalePriceListItem::query()->delete();
    expect(SalePriceList::explainPrice($client, $this->product, null))
        ->toMatchArray(['price' => 5000.0, 'source' => 'base']);

    expect(SalePriceList::explainPrice(null, null, 'type_sans_tarif'))
        ->toMatchArray(['price' => null, 'source' => 'none']);
});

test('un article de STOCK obtient le prix par article de son catalogue', function () {
    // Le sélecteur « stock » lisait la grille parallèle par NOM de produit ; il
    // remonte maintenant à l'article du catalogue et à son tarif.
    $list = tariff('Grossiste', [['product_id' => $this->product->id, 'product_type' => 'produits_finis', 'unit_price' => 3800]]);
    $client = pricedClient('G', $list->id);

    $this->actingAs($this->adminUser)
        ->getJson(route('sales.suggest-price', ['stock_id' => $this->stock->id, 'client_id' => $client->id]))
        ->assertOk()
        ->assertJson(['price' => 3800.0, 'source' => 'article']);
});

test('les trois sélecteurs de l’écran de vente donnent le MÊME prix', function () {
    // La contradiction constatée : catalogue → tarif négocié, stock → premier
    // prix trouvé par nom.
    $list = tariff('Grossiste', [['product_type' => 'produits_finis', 'unit_price' => 4000]]);
    $client = pricedClient('G', $list->id);

    foreach ([
        ['product_id' => $this->product->id],   // catalogue
        ['stock_id' => $this->stock->id],       // stock
        ['product_type' => 'produits_finis'],   // type libre
    ] as $query) {
        $this->actingAs($this->adminUser)
            ->getJson(route('sales.suggest-price', $query + ['client_id' => $client->id]))
            ->assertOk()
            ->assertJson(['price' => 4000.0]);
    }
});

test('l’écran de vente ne porte plus de seconde grille tarifaire', function () {
    $source = file_get_contents(resource_path('views/sales/create.blade.php'));

    expect($source)->not->toContain('$formattedPrices')
        ->and($source)->not->toContain('const prices =')
        // et le fantôme en commentaire, qui laissait croire à une correction faite
        ->and($source)->not->toContain('version initiale avant correction');

    // Un prix tapé à la main ne doit plus jamais être écrasé.
    expect($source)->toContain('manual_price');
});

test('le système de tarification parallèle a bien disparu', function () {
    expect(file_exists(app_path('Services/PricingService.php')))->toBeFalse()
        ->and(file_exists(app_path('Models/PriceList.php')))->toBeFalse();

    // La TABLE est laissée intacte : elle peut contenir des prix saisis
    // directement en base, qu'il appartient à la ferme de reporter dans un
    // groupe de prix. La supprimer serait une perte de données.
    expect(Schema::hasTable('price_lists'))->toBeTrue();
});

test('le POS (catalogue) expose les articles liés au stock avec prix et photo', function () {
    $resp = $this->actingAs($this->adminUser)->get(route('pos.index'))->assertOk();

    $product = collect($resp->viewData('products'))->firstWhere('name', 'Poulet entier');
    expect($product)->not->toBeNull()
        ->and($product['price'])->toBe(5000.0)
        ->and($product['qty'])->toBe(100.0)
        ->and($product)->toHaveKey('photo');
});

test('l’écran de vente s’affiche sans la grille supprimée', function () {
    tariff('Standard', [['product_type' => 'produits_finis', 'unit_price' => 5000]], default: true);

    $this->actingAs($this->adminUser)->get(route('sales.create'))
        ->assertOk()
        ->assertSee('suggestPrice', false)
        ->assertSee('price_origin', false);
});
