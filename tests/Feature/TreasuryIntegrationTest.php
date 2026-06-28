<?php

use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->caisse = TreasuryAccount::create(['name' => 'Caisse', 'type' => 'caisse', 'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true]);
    $this->momo   = TreasuryAccount::create(['name' => 'Orange Money', 'type' => 'mobile_money', 'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true]);
    $this->banque = TreasuryAccount::create(['name' => 'BICIGUI', 'type' => 'banque', 'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true]);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-TRESO', 'name' => 'Client tréso',
        'type' => 'particulier', 'credit_limit' => 0, 'balance' => 0, 'status' => 'actif',
    ]);
});

function tresoSale(Client $client): Sale
{
    return (new \App\Actions\Sale\CreateSale())->execute([
        'client_id' => $client->id, 'sale_date' => now()->toDateString(), 'type' => 'bon_livraison', 'tax_rate' => 0,
        'items' => [[
            'product_type' => 'oeufs', 'product_name' => 'Œuf M', 'quantity' => 10, 'unit' => 'alveole', 'unit_price' => 2000,
        ]],
    ]);
}

test('le mapping mode → compte suit le type (espèces→caisse, OM→mobile, virement→banque)', function () {
    expect(TreasuryAccount::resolveForMethod('especes')->id)->toBe($this->caisse->id)
        ->and(TreasuryAccount::resolveForMethod('orange_money')->id)->toBe($this->momo->id)
        ->and(TreasuryAccount::resolveForMethod('mobile_money')->id)->toBe($this->momo->id)
        ->and(TreasuryAccount::resolveForMethod('virement')->id)->toBe($this->banque->id);
});

test('le compte marqué par défaut pour un mode prime sur le type', function () {
    $autreCaisse = TreasuryAccount::create(['name' => 'Caisse 2', 'type' => 'caisse', 'current_balance' => 0, 'is_active' => true, 'default_for_method' => 'especes']);
    expect(TreasuryAccount::resolveForMethod('especes')->id)->toBe($autreCaisse->id);
});

test('un encaissement de vente alimente le compte du mode de paiement', function () {
    $sale = tresoSale($this->client);
    (new \App\Actions\Sale\ValidateSale())->execute($sale);

    (new \App\Actions\Sale\RecordPayment())->execute($sale->fresh(), [
        'amount' => 20000, 'method' => 'orange_money', 'payment_date' => now()->toDateString(),
    ]);

    expect((float) $this->momo->fresh()->current_balance)->toBe(20000.0)   // OM crédité
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(0.0); // caisse intacte

    $tx = TreasuryTransaction::where('source_type', (new Payment)->getMorphClass())->first();
    expect($tx)->not->toBeNull()->and($tx->direction)->toBe('in')->and($tx->category)->toBe('vente');
});

test('un paiement avec compte explicite (override) ignore le mapping par défaut', function () {
    $sale = tresoSale($this->client);
    (new \App\Actions\Sale\ValidateSale())->execute($sale);

    Payment::create([
        'sale_id' => $sale->id, 'amount' => 5000, 'payment_date' => now()->toDateString(),
        'method' => 'especes', 'treasury_account_id' => $this->banque->id, 'received_by' => $this->adminUser->id,
    ]);

    expect((float) $this->banque->fresh()->current_balance)->toBe(5000.0)   // override respecté
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(0.0);
});

test('supprimer un paiement contre-passe l\'écriture de trésorerie', function () {
    $sale = tresoSale($this->client);
    (new \App\Actions\Sale\ValidateSale())->execute($sale);
    $payment = (new \App\Actions\Sale\RecordPayment())->execute($sale->fresh(), [
        'amount' => 8000, 'method' => 'especes', 'payment_date' => now()->toDateString(),
    ]);
    expect((float) $this->caisse->fresh()->current_balance)->toBe(8000.0);

    $payment->delete();
    expect((float) $this->caisse->fresh()->current_balance)->toBe(0.0)
        ->and(TreasuryTransaction::where('source_id', $payment->id)->where('source_type', $payment->getMorphClass())->exists())->toBeFalse();
});

test('une dépense ne décaisse qu\'À LA VALIDATION', function () {
    $expense = Expense::create([
        'reference' => 'DEP-X', 'category' => 'fournitures', 'label' => 'Sacs', 'amount' => 30000,
        'expense_date' => now()->toDateString(), 'status' => 'en_attente', 'payment_method' => 'especes', 'user_id' => $this->adminUser->id,
    ]);
    expect((float) $this->caisse->fresh()->current_balance)->toBe(0.0); // en attente → rien

    $expense->update(['status' => 'valide']);
    expect((float) $this->caisse->fresh()->current_balance)->toBe(-30000.0); // validée → décaissée

    // Annulation → contre-passation.
    $expense->update(['status' => 'annule']);
    expect((float) $this->caisse->fresh()->current_balance)->toBe(0.0);
});

test('la comptabilisation est idempotente (pas de double-décaissement)', function () {
    $expense = Expense::create([
        'reference' => 'DEP-Y', 'category' => 'fournitures', 'label' => 'Maïs', 'amount' => 15000,
        'expense_date' => now()->toDateString(), 'status' => 'valide', 'payment_method' => 'especes', 'user_id' => $this->adminUser->id,
    ]);
    // Un re-save sans changement ne doit pas re-décaisser.
    $expense->touch();
    $expense->update(['notes' => 'note ajoutée']);

    expect((float) $this->caisse->fresh()->current_balance)->toBe(-15000.0)
        ->and(TreasuryTransaction::where('source_id', $expense->id)->where('source_type', $expense->getMorphClass())->count())->toBe(1);
});
