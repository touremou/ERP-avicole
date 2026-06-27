<?php

use App\Models\Expense;
use App\Models\Module;
use App\Models\Provider;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\TreasuryAccount;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

test('le module Finance atterrit sur le hub', function () {
    expect(Module::landingRoute('depenses'))->toBe('finance.index');
});

test('le hub finance agrège trésorerie, dépenses du mois et dettes fournisseurs', function () {
    TreasuryAccount::create([
        'name' => 'Caisse', 'type' => 'caisse', 'opening_balance' => 0, 'current_balance' => 100000, 'is_active' => true,
    ]);

    Expense::create([
        'reference' => 'DEP-T1', 'category' => 'fournitures', 'label' => 'Sacs', 'amount' => 50000,
        'expense_date' => now()->toDateString(), 'status' => 'valide', 'user_id' => $this->adminUser->id,
    ]);

    $provider = Provider::create(['name' => 'Fournisseur X', 'type' => 'Aliment', 'phone' => '620', 'status' => 'Actif']);
    $inv = SupplierInvoice::create([
        'provider_id' => $provider->id, 'reference' => 'ACH-T1', 'invoice_date' => now()->toDateString(),
        'category' => 'fournitures', 'label' => 'Provende', 'total_amount' => 80000, 'status' => 'valide', 'user_id' => $this->adminUser->id,
    ]);
    SupplierPayment::create([
        'supplier_invoice_id' => $inv->id, 'amount' => 30000, 'payment_date' => now()->toDateString(),
        'method' => 'especes', 'paid_by' => $this->adminUser->id,
    ]);

    $kpis = $this->get(route('finance.index'))->assertOk()->assertSee('Finance')->viewData('kpis');

    expect($kpis['treasury'])->toBe(100000.0)
        ->and($kpis['month_expenses'])->toBe(50000.0)
        ->and($kpis['supplier_debt'])->toBe(50000.0) // 80 000 − 30 000
        ->and($kpis['accounts_count'])->toBe(1);
});
