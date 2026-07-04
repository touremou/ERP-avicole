<?php

use App\Models\CashRegisterSession;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Chantier B (pré-MEP 2026-07-04) — POS « façon balance » (écran DIGI) :
 * - code PLU (= sku produit) exposé à la grille caisse ;
 * - touches favorites (products.is_favorite, gérées au catalogue) ;
 * - attribution NOMINATIVE de la vente à un vendeur-employé (distinct du
 *   caissier connecté), visible sur le ticket et agrégée au Z ;
 * - le vendeur est optionnel : sans lui, le checkout fonctionne comme avant
 *   (palier sans module annuaire).
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData(); // building/employee/provider — dont l'employé vendeur

    $this->stock = Stock::factory()->create([
        'item_name'        => 'Oeufs calibre L',
        'category'         => 'oeufs',
        'unit'             => 'KG',
        'current_quantity' => 50,
    ]);

    $this->product = Product::create([
        'name'         => 'Œufs plateau L',
        'sku'          => '12',
        'product_type' => 'oeufs',
        'stock_id'     => $this->stock->id,
        'unit'         => 'kg',
        'base_price'   => 15000,
        'is_active'    => true,
        'is_favorite'  => true,
    ]);

    // Session de caisse ouverte : prérequis de toute vente POS.
    CashRegisterSession::create([
        'user_id'       => $this->managerUser->id,
        'opened_at'     => now(),
        'opening_float' => 0,
        'status'        => 'open',
    ]);
});

test('la grille caisse expose PLU, favori et classement meilleures ventes', function () {
    $response = $this->actingAs($this->managerUser)
        ->get(route('pos.index'))
        ->assertOk()
        ->assertSee('Plus vendus'); // onglet top ventes rendu

    // Assertion sur les DONNÉES de la vue (indépendante de l'encodage Js::from).
    $grid = collect($response->viewData('products'));
    $row  = $grid->firstWhere('id', $this->product->id);

    expect($row)->not->toBeNull();
    expect($row['sku'])->toBe('12');
    expect($row['fav'])->toBeTrue();
    expect($row)->toHaveKey('sold');
});

test('checkout avec vendeur nominatif : la vente porte l\'employé, le ticket l\'affiche', function () {
    $this->actingAs($this->managerUser)
        ->post(route('pos.checkout'), [
            'payment_method'     => 'especes',
            'seller_employee_id' => $this->employee->id,
            'items'              => [[
                'product_id' => $this->product->id,
                'quantity'   => 2,
                'unit_price' => 15000,
            ]],
        ])
        ->assertSessionHas('success');

    $sale = Sale::latest('id')->first();
    expect($sale->seller_employee_id)->toBe($this->employee->id);
    expect($sale->status)->toBe('livre');

    // Ticket : le vendeur apparaît nominativement.
    $this->actingAs($this->managerUser)
        ->get(route('pos.receipt', $sale))
        ->assertOk()
        ->assertSee('Vendeur')
        ->assertSee($this->employee->first_name);

    // Z du jour : bloc « Ventes par vendeur » alimenté.
    $this->actingAs($this->managerUser)
        ->get(route('pos.report'))
        ->assertOk()
        ->assertSee('Ventes par vendeur')
        ->assertSee($this->employee->first_name);
});

test('checkout sans vendeur : la vente passe comme avant (vendeur optionnel)', function () {
    $this->actingAs($this->managerUser)
        ->post(route('pos.checkout'), [
            'payment_method' => 'especes',
            'items'          => [[
                'product_id' => $this->product->id,
                'quantity'   => 1,
                'unit_price' => 15000,
            ]],
        ])
        ->assertSessionHas('success');

    $sale = Sale::latest('id')->first();
    expect($sale->seller_employee_id)->toBeNull();
    expect((float) $this->stock->fresh()->current_quantity)->toEqual(49.0);
});

test('un vendeur forgé inexistant est refusé par la validation', function () {
    $this->actingAs($this->managerUser)
        ->post(route('pos.checkout'), [
            'payment_method'     => 'especes',
            'seller_employee_id' => 99999,
            'items'              => [[
                'product_id' => $this->product->id,
                'quantity'   => 1,
                'unit_price' => 15000,
            ]],
        ])
        ->assertSessionHasErrors('seller_employee_id');

    expect(Sale::count())->toBe(0);
});
