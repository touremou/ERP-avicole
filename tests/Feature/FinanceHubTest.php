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
        // AUJOURD'HUI, pas « début de mois + 1 jour » : le 1er du mois, cette
        // date tombe DEMAIN et la dépense sort de la fenêtre [début de mois →
        // aujourd'hui]. Le test échouait alors une fois par mois, sans qu'aucun
        // défaut n'existe — et un test qui ment un jour sur trente use la
        // confiance plus vite qu'un vrai échec.
        'expense_date' => now()->toDateString(), 'status' => 'valide', 'user_id' => $this->adminUser->id,
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

test('la dette fournisseurs du hub est celle de l’écran des factures : un trop-perçu n’efface pas une autre dette', function () {
    $x = Provider::create(['name' => 'Fournisseur X', 'type' => 'Aliment', 'phone' => '620', 'status' => 'Actif']);
    $y = Provider::create(['name' => 'Fournisseur Y', 'type' => 'Aliment', 'phone' => '621', 'status' => 'Actif']);

    // Y : 100 000 dus. X : facture ramenée à 60 000 après deux règlements de
    // 40 000 — 20 000 de trop-perçu, qui n'est pas un règlement de Y.
    SupplierInvoice::create(['provider_id' => $y->id, 'reference' => 'ACH-Y', 'invoice_date' => now()->toDateString(),
        'category' => 'fournitures', 'label' => 'Y', 'total_amount' => 100000, 'status' => 'valide', 'user_id' => $this->adminUser->id]);
    $fx = SupplierInvoice::create(['provider_id' => $x->id, 'reference' => 'ACH-X', 'invoice_date' => now()->toDateString(),
        'category' => 'fournitures', 'label' => 'X', 'total_amount' => 80000, 'status' => 'valide', 'user_id' => $this->adminUser->id]);
    foreach ([1, 2] as $n) {
        SupplierPayment::create(['supplier_invoice_id' => $fx->id, 'amount' => 40000, 'payment_date' => now()->toDateString(),
            'method' => 'especes', 'paid_by' => $this->adminUser->id]);
    }
    $fx->update(['total_amount' => 60000]);

    $hub = $this->get(route('finance.index'))->assertOk()->viewData('kpis');
    $factures = $this->get(route('purchases.index'))->assertOk()->viewData('stats');

    expect($hub['supplier_debt'])->toBe(100000.0)
        ->and($hub['supplier_debt'])->toEqual($factures['total_due']);
});

test('l’autonomie de caisse se mesure aux décaissements réels : règlements fournisseurs compris, transferts exclus', function () {
    $caisse = TreasuryAccount::create([
        'name' => 'Caisse', 'type' => 'caisse', 'opening_balance' => 0, 'current_balance' => 600000, 'is_active' => true,
    ]);
    $sortie = fn (string $categorie, float $montant) => App\Models\TreasuryTransaction::create([
        'treasury_account_id' => $caisse->id, 'direction' => 'out', 'amount' => $montant,
        'transaction_date' => now()->subDays(10)->toDateString(), 'category' => $categorie,
    ]);
    $sortie('depense', 60000);    // registre des dépenses
    $sortie('achat', 540000);     // aliment réglé au fournisseur
    $sortie('transfert', 900000); // caisse → banque : l'argent reste à la ferme
    $caisse->update(['current_balance' => 600000]);

    $kpis = $this->get(route('finance.index'))->assertOk()->viewData('kpis');

    // 600 000 ÷ (600 000 / 3 mois) = 3 mois — et non 30, sur le seul registre.
    expect($kpis['runway_months'])->toBe(3.0);
});
