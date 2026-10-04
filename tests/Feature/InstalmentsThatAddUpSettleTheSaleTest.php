<?php

use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DES ACOMPTES QUI FONT LE COMPTE SOLDENT LA VENTE.
 *
 * Le statut de paiement comparait la somme BRUTE des règlements au total. Sur
 * SQLite — moteur documenté pour les petites installations — une colonne
 * DECIMAL est stockée en flottant : 10,10 + 20,20 y vaut 30,299999999999997.
 * Une vente de 30,30 réglée en deux fois restait donc « partiel » POUR
 * TOUJOURS, alors que son reste dû (calculé, lui, sur la valeur arrondie)
 * valait 0 : plus aucun règlement accepté, et la vente comptée dans les
 * créances. Les montants à centimes naissent des remises en pourcentage.
 *
 * Les factures fournisseurs comparaient déjà au centime près
 * (SupplierInvoice::getPaidAmountAttribute) : la vente suit la même règle.
 */

beforeEach(fn () => $this->setUpRbac());

function venteEnAcomptes(float $total): Sale
{
    $client = \App\Models\Client::create([
        'farm_id' => session('current_farm_id'), 'client_id' => 'CLI-'.fake()->unique()->numerify('###'),
        'name' => 'Client', 'type' => 'particulier', 'category' => 'detaillant',
    ]);
    $sale = Sale::create([
        'farm_id' => session('current_farm_id'), 'reference' => 'BL-'.fake()->unique()->numerify('#####'),
        'client_id' => $client->id, 'user_id' => \App\Models\User::value('id'), 'sale_date' => now(),
        'type' => 'bon_livraison', 'status' => 'brouillon', 'tax_rate' => 0,
    ]);
    SaleItem::create([
        'farm_id' => session('current_farm_id'), 'sale_id' => $sale->id, 'product_type' => 'oeufs',
        'product_name' => 'Œufs', 'quantity' => 1, 'unit' => 'alveole', 'unit_price' => $total, 'total' => $total,
    ]);
    $sale->recalculateTotals();

    return $sale->fresh();
}

function reglerAcompte(Sale $sale, float $montant): void
{
    Payment::create([
        'sale_id' => $sale->id, 'amount' => $montant, 'payment_date' => now()->toDateString(),
        'method' => 'especes', 'received_by' => \App\Models\User::value('id'),
    ]);
    $sale->refreshPaymentStatus();
}

test('30,30 réglés en 10,10 + 20,20 : la vente est SOLDÉE', function () {
    $sale = venteEnAcomptes(30.30);

    reglerAcompte($sale, 10.10);
    reglerAcompte($sale, 20.20);

    expect($sale->fresh()->payment_status)->toBe('solde')
        ->and((float) $sale->fresh()->remaining_amount)->toBe(0.0);
});

test('un centime manquant laisse la vente partielle — la borne', function () {
    $sale = venteEnAcomptes(30.30);

    reglerAcompte($sale, 10.10);
    reglerAcompte($sale, 20.19);

    expect($sale->fresh()->payment_status)->toBe('partiel')
        ->and((float) $sale->fresh()->remaining_amount)->toBe(0.01);
});

test('le statut et le reste dû disent toujours la même chose', function () {
    $sale = venteEnAcomptes(0.30);

    reglerAcompte($sale, 0.10);
    reglerAcompte($sale, 0.20);

    $sale = $sale->fresh();
    expect($sale->payment_status === 'solde')->toBe((float) $sale->remaining_amount === 0.0);
});
