<?php

use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use App\Services\TreasuryService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function account(string $name, string $type, float $opening): TreasuryAccount
{
    return TreasuryAccount::create([
        'name' => $name, 'type' => $type,
        'opening_balance' => $opening, 'current_balance' => $opening,
    ]);
}

test('créer un compte fixe le solde au solde d\'ouverture', function () {
    $this->actingAs($this->adminUser)
        ->post(route('treasury.account.store'), ['name' => 'Caisse', 'type' => 'caisse', 'opening_balance' => 100000])
        ->assertSessionHas('success');

    $acc = TreasuryAccount::first();
    expect((float) $acc->current_balance)->toBe(100000.0)
        ->and((float) $acc->opening_balance)->toBe(100000.0);
});

test('un mouvement entrée/sortie met à jour le solde', function () {
    $acc = account('Caisse', 'caisse', 100000);

    $this->actingAs($this->adminUser)->post(route('treasury.movement', $acc), [
        'direction' => 'in', 'amount' => 50000, 'date' => now()->toDateString(),
    ])->assertSessionHas('success');

    $this->actingAs($this->adminUser)->post(route('treasury.movement', $acc), [
        'direction' => 'out', 'amount' => 30000, 'date' => now()->toDateString(),
    ])->assertSessionHas('success');

    expect((float) $acc->fresh()->current_balance)->toBe(120000.0); // 100k + 50k − 30k
});

test('une sortie supérieure au solde est refusée (solde intact)', function () {
    $acc = account('Caisse', 'caisse', 10000);

    $this->actingAs($this->adminUser)->post(route('treasury.movement', $acc), [
        'direction' => 'out', 'amount' => 50000, 'date' => now()->toDateString(),
    ])->assertSessionHas('error');

    expect((float) $acc->fresh()->current_balance)->toBe(10000.0)
        ->and(TreasuryTransaction::count())->toBe(0);
});

test('un transfert déplace le solde et crée deux écritures appariées', function () {
    $caisse = account('Caisse', 'caisse', 100000);
    $banque = account('Banque', 'banque', 0);

    $this->actingAs($this->adminUser)->post(route('treasury.transfer'), [
        'from_id' => $caisse->id, 'to_id' => $banque->id, 'amount' => 40000, 'date' => now()->toDateString(),
    ])->assertSessionHas('success');

    expect((float) $caisse->fresh()->current_balance)->toBe(60000.0)
        ->and((float) $banque->fresh()->current_balance)->toBe(40000.0)
        ->and(TreasuryTransaction::where('category', 'transfert')->count())->toBe(2);
});

test('un transfert au solde insuffisant est refusé', function () {
    $caisse = account('Caisse', 'caisse', 10000);
    $banque = account('Banque', 'banque', 0);

    $this->actingAs($this->adminUser)->post(route('treasury.transfer'), [
        'from_id' => $caisse->id, 'to_id' => $banque->id, 'amount' => 50000, 'date' => now()->toDateString(),
    ])->assertSessionHas('error');

    expect((float) $caisse->fresh()->current_balance)->toBe(10000.0)
        ->and((float) $banque->fresh()->current_balance)->toBe(0.0);
});

test('recomputeBalance corrige toute dérive du solde', function () {
    $acc = account('Caisse', 'caisse', 10000);
    (new TreasuryService())->record($acc, 'in', 5000, ['date' => now()->toDateString()]);

    $acc->update(['current_balance' => 999]); // dérive simulée
    $acc->recomputeBalance();

    expect((float) $acc->fresh()->current_balance)->toBe(15000.0);
});
