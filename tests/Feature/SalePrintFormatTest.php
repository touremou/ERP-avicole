<?php

use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function makeSale(): Sale
{
    $farm = session('current_farm_id');
    $client = Client::create([
        'farm_id' => $farm, 'client_id' => 'CLI-PRN', 'name' => 'Boutique Test',
        'type' => 'entreprise', 'category' => 'detaillant',
    ]);
    $sale = Sale::create([
        'farm_id' => $farm, 'reference' => 'BL-2026-000999', 'client_id' => $client->id,
        'user_id' => \App\Models\User::value('id'), 'sale_date' => now(), 'type' => 'bon_livraison',
        'status' => 'valide', 'subtotal' => 6000, 'total_amount' => 6000, 'paid_amount' => 6000,
        'payment_status' => 'paye',
    ]);
    SaleItem::create([
        'farm_id' => $farm, 'sale_id' => $sale->id, 'product_type' => 'oeufs',
        'product_name' => 'Œufs calibre L', 'quantity' => 2, 'unit' => 'Alvéole',
        'unit_price' => 3000, 'total' => 6000,
    ]);
    return $sale;
}

test('l\'impression par défaut est en A4 (facture classique)', function () {
    $sale = makeSale();

    $this->actingAs($this->adminUser)
        ->get(route('sales.print', $sale))
        ->assertOk()
        ->assertSee('BL-2026-000999')
        ->assertDontSee('size: 80mm', false); // pas la feuille de style ticket
});

test('le format thermal rend un ticket 80 mm', function () {
    $sale = makeSale();

    $this->actingAs($this->adminUser)
        ->get(route('sales.print', ['sale' => $sale, 'format' => 'thermal']))
        ->assertOk()
        ->assertSee('BL-2026-000999')
        ->assertSee('80mm', false)
        ->assertSee('Œufs calibre L');
});

test('le paramètre ventes.print_format pilote le format par défaut', function () {
    Setting::set('ventes.print_format', 'thermal');
    $sale = makeSale();

    $this->actingAs($this->adminUser)
        ->get(route('sales.print', $sale))
        ->assertOk()
        ->assertSee('80mm', false); // ticket par défaut désormais
});
