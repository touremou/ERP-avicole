<?php

use App\Models\Product;
use App\Models\Setting;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE MONTANT ANNONCÉ AU CLIENT DOIT ÊTRE CELUI QUI EST ENREGISTRÉ.
 *
 * Certaines coupures ne circulent plus en Guinée : la ferme fixe une coupure
 * (`ventes.cash_rounding`) et le serveur y ramène le total au moment
 * d'enregistrer la vente (`cash_round()`, appelé par Sale::recalculateTotals()
 * et par le POS).
 *
 * Mais cette règle n'existait QUE côté serveur. Les deux écrans qui annoncent la
 * somme à encaisser — la caisse web et l'écran de vente mobile — affichaient le
 * total BRUT. Avec une coupure de 1 000, le caissier lisait 55 100, encaissait
 * 55 100, et la vente était écrite à 55 000 : un écart de caisse à chaque vente,
 * invisible jusqu'au rapprochement de clôture.
 *
 * Côté terrain, le problème était structurel : la PWA ne recevait AUCUN réglage
 * de la ferme, elle ne pouvait donc honorer aucune règle de ce genre.
 */

beforeEach(function () {
    $this->setUpRbac();

    $stock = Stock::create([
        'category' => Stock::CAT_PRODUITS_FINIS, 'item_name' => 'Poulet entier',
        'unit' => 'piece', 'current_quantity' => 100, 'unit_price' => 0,
        'last_unit_price' => 0, 'alert_threshold' => 5,
    ]);

    $this->product = Product::create([
        'name' => 'Poulet entier', 'product_type' => 'produits_finis', 'stock_id' => $stock->id,
        'unit' => 'piece', 'base_price' => 5100, 'is_active' => true,
    ]);
});

test('l’arrondi de caisse ramène le total à la coupure la plus proche', function () {
    expect(cash_round(55100, 1000))->toBe(55000.0)
        ->and(cash_round(55600, 1000))->toBe(56000.0)
        ->and(cash_round(55100, 0))->toBe(55100.0);
});

test('la caisse web connaît la coupure paramétrée', function () {
    // L'écran affichait le total brut : la règle d'arrondi lui était inconnue.
    Setting::set('ventes.cash_rounding', 1000);

    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 0]);

    $this->actingAs($this->adminUser)->get(route('pos.index'))
        ->assertOk()
        ->assertSee('const CASH_STEP = 1000', false)
        ->assertSee('À encaisser')
        ->assertSee('roundingAdjustment', false);
});

test('la caisse enregistre bien le montant arrondi', function () {
    Setting::set('ventes.cash_rounding', 1000);

    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 0]);

    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 5100]],
    ]);

    // 5 100 ramené à la coupure de 1 000 : c'est ce montant que l'écran annonce
    // désormais, et non 5 100.
    expect((float) \App\Models\Sale::latest('id')->first()->total_amount)->toBe(5000.0);
});

test('la PWA reçoit les règles de la ferme dont le terrain a besoin', function () {
    // Elle n'en recevait AUCUNE : impossible d'honorer hors réseau une règle
    // définie par la ferme.
    Setting::set('ventes.cash_rounding', 500);

    $token = $this->adminUser->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('settings.cash_rounding', 500)
        ->assertJsonStructure(['settings' => ['currency', 'cash_rounding']]);
});

test('le miroir mobile de l’arrondi existe et suit la même règle', function () {
    $pricing = file_get_contents(base_path('mobile/src/offline/pricing.ts'));

    expect($pricing)->toContain('export function cashRound')
        // Même formule que cash_round() : coupure la plus proche, 0 = désactivé.
        ->and($pricing)->toContain('Math.round(value / cut) * cut');

    $screen = file_get_contents(base_path('mobile/src/features/commerce/SaleScreen.tsx'));

    expect($screen)->toContain('cashRound(subtotal, cashStep)')
        ->and($screen)->toContain('me?.settings?.cash_rounding')
        // Et le libellé ne dit plus « Total » mais ce qu'il faut encaisser.
        ->and($screen)->toContain('À encaisser : :amount');
});

test('sans coupure paramétrée, rien ne change', function () {
    // Le réglage vaut 0 par défaut : l'arrondi ne doit pas s'inviter.
    Setting::set('ventes.cash_rounding', 0);

    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 0]);

    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 5100]],
    ]);

    expect((float) \App\Models\Sale::latest('id')->first()->total_amount)->toBe(5100.0);
});
