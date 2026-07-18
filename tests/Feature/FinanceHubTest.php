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

    // Intégration trésorerie ↔ transactions : la Caisse est débitée de la
    // dépense validée (50 000) ET du règlement fournisseur espèces (30 000) :
    // 100 000 − 50 000 − 30 000 = 20 000.
    expect($kpis['treasury'])->toBe(20000.0)
        ->and($kpis['opex_month'])->toBe(50000.0)
        ->and($kpis['supplier_debt'])->toBe(50000.0) // 80 000 − 30 000
        ->and($kpis['accounts_count'])->toBe(1);
});

test('le hub finance calcule les indicateurs de pilotage (Δ charges, autonomie, DPO)', function () {
    TreasuryAccount::create([
        'name' => 'Banque', 'type' => 'banque', 'opening_balance' => 0, 'current_balance' => 300000, 'is_active' => true,
    ]);

    // Charges : 60 000 ce mois-ci, 40 000 le mois dernier → Δ = +50 %.
    Expense::create([
        'reference' => 'DEP-M0', 'category' => 'fournitures', 'label' => 'Mois courant', 'amount' => 60000,
        'expense_date' => now()->startOfMonth()->addDay()->toDateString(), 'status' => 'valide', 'user_id' => $this->adminUser->id,
    ]);
    Expense::create([
        'reference' => 'DEP-M1', 'category' => 'fournitures', 'label' => 'Mois précédent', 'amount' => 40000,
        'expense_date' => now()->subMonthNoOverflow()->startOfMonth()->addDay()->toDateString(), 'status' => 'valide', 'user_id' => $this->adminUser->id,
    ]);

    $kpis = $this->get(route('finance.index'))->assertOk()->viewData('kpis');

    expect($kpis['opex_month'])->toBe(60000.0)
        ->and($kpis['opex_delta'])->toBe(50.0)           // (60−40)/40
        ->and($kpis['runway_months'])->not->toBeNull()   // trésorerie / charge moyenne
        ->and($kpis['runway_months'])->toBeGreaterThan(0);
});

test('sans charge de référence, l\'autonomie de caisse est nulle (pas de division par zéro)', function () {
    TreasuryAccount::create([
        'name' => 'Caisse vide', 'type' => 'caisse', 'opening_balance' => 0, 'current_balance' => 500000, 'is_active' => true,
    ]);

    $kpis = $this->get(route('finance.index'))->assertOk()->viewData('kpis');

    expect($kpis['runway_months'])->toBeNull()
        ->and($kpis['opex_delta'])->toBeNull()
        ->and($kpis['dpo_days'])->toBeNull();
});
