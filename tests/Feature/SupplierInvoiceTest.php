<?php

use App\Models\Expense;
use App\Models\Provider;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->provider = Provider::create([
        'name' => 'Provende SARL', 'type' => 'Aliment', 'phone' => '620000000', 'status' => 'Actif',
    ]);
});

/** Achat (brouillon) prêt à valider. */
function draftPurchase(Provider $provider, float $total = 100000, array $over = []): SupplierInvoice
{
    return SupplierInvoice::create(array_merge([
        'provider_id'  => $provider->id,
        'reference'    => 'ACH-00001',
        'invoice_date' => now()->toDateString(),
        'category'     => 'fournitures',
        'label'        => 'Achat test',
        'total_amount' => $total,
        'status'       => 'brouillon',
        'user_id'      => \Illuminate\Support\Facades\Auth::id(),
    ], $over));
}

test('créer un achat via le formulaire génère une référence et reste en brouillon', function () {
    $this->post(route('purchases.store'), [
        'provider_id'  => $this->provider->id,
        'invoice_date' => now()->toDateString(),
        'category'     => 'fournitures',
        'label'        => '20 sacs de provende',
        'total_amount' => 250000,
    ])->assertRedirect();

    $inv = SupplierInvoice::latest('id')->first();
    expect($inv)->not->toBeNull()
        ->and($inv->status)->toBe('brouillon')
        ->and($inv->reference)->toStartWith('ACH-')
        ->and(Expense::count())->toBe(0); // pas encore imputé au P&L
});

test('valider un achat poste UNE dépense « valide » liée (source unique P&L)', function () {
    $inv = draftPurchase($this->provider, 100000);

    $this->put(route('purchases.validate', $inv))->assertRedirect()->assertSessionHas('success');

    $inv->refresh();
    expect($inv->status)->toBe('valide')
        ->and($inv->expense_id)->not->toBeNull()
        ->and(Expense::count())->toBe(1);

    $expense = $inv->expense;
    expect($expense->status)->toBe('valide')
        ->and((float) $expense->amount)->toBe(100000.0)
        ->and($expense->supplier_name)->toBe('Provende SARL')
        ->and($expense->reference)->toBe($inv->reference); // traçabilité
});

test('un règlement réduit le reste dû ; la dette fournisseur = total − payé', function () {
    $inv = draftPurchase($this->provider, 100000);
    $this->put(route('purchases.validate', $inv));

    $this->post(route('purchases.pay', $inv), [
        'amount' => 30000, 'method' => 'virement', 'payment_date' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHas('success');

    $inv->refresh()->load('payments');
    expect($inv->remaining_amount)->toBe(70000.0)
        ->and($inv->payment_status)->toBe('partiel')
        ->and($this->provider->outstandingDebt())->toBe(70000.0);

    // Solde complet → dette nulle.
    $this->post(route('purchases.pay', $inv), [
        'amount' => 70000, 'method' => 'especes', 'payment_date' => now()->toDateString(),
    ])->assertRedirect();

    $inv->refresh()->load('payments');
    expect($inv->remaining_amount)->toBe(0.0)
        ->and($inv->payment_status)->toBe('solde')
        ->and($this->provider->outstandingDebt())->toBe(0.0);
});

test('régler plus que le reste dû est refusé', function () {
    $inv = draftPurchase($this->provider, 50000);
    $this->put(route('purchases.validate', $inv));

    $this->post(route('purchases.pay', $inv), [
        'amount' => 60000, 'method' => 'especes', 'payment_date' => now()->toDateString(),
    ])->assertSessionHas('error');

    expect(SupplierPayment::count())->toBe(0);
});

test('on ne peut pas régler un achat encore en brouillon', function () {
    $inv = draftPurchase($this->provider, 50000);

    $this->post(route('purchases.pay', $inv), [
        'amount' => 10000, 'method' => 'especes', 'payment_date' => now()->toDateString(),
    ])->assertSessionHas('error');

    expect(SupplierPayment::count())->toBe(0);
});

test('annuler un achat le retire de la dette ET annule sa dépense', function () {
    $inv = draftPurchase($this->provider, 80000);
    $this->put(route('purchases.validate', $inv));
    expect($this->provider->outstandingDebt())->toBe(80000.0);

    $this->put(route('purchases.cancel', $inv))->assertRedirect()->assertSessionHas('success');

    $inv->refresh();
    expect($inv->status)->toBe('annule')
        ->and($inv->expense->status)->toBe('annule')      // sort du P&L
        ->and($this->provider->outstandingDebt())->toBe(0.0); // sort de la dette
});

test('le relevé fournisseur réconcilie à la dette en cours', function () {
    $inv = draftPurchase($this->provider, 100000);
    $this->put(route('purchases.validate', $inv));
    $this->post(route('purchases.pay', $inv), [
        'amount' => 40000, 'method' => 'especes', 'payment_date' => now()->toDateString(),
    ]);

    $st = $this->get(route('purchases.statement', $this->provider))->assertOk()->viewData('statement');

    expect($st['total_debit'])->toBe(100000.0)
        ->and($st['total_credit'])->toBe(40000.0)
        ->and($st['balance'])->toBe(60000.0)
        ->and($st['rows'])->toHaveCount(2)
        ->and($st['rows']->last()['balance'])->toBe(60000.0);
});

test('le relevé fournisseur s\'exporte en PDF', function () {
    $inv = draftPurchase($this->provider, 100000);
    $this->put(route('purchases.validate', $inv));

    $this->get(route('purchases.statement.pdf', $this->provider))
        ->assertOk()
        ->assertDownload('releve-fournisseur-' . $this->provider->provider_id . '.pdf');
});

test('le journal des achats est accessible et agrège les dettes', function () {
    $inv = draftPurchase($this->provider, 100000);
    $this->put(route('purchases.validate', $inv));

    $stats = $this->get(route('purchases.index'))->assertOk()->viewData('stats');
    expect($stats['total_billed'])->toBe(100000.0)
        ->and($stats['total_due'])->toBe(100000.0);
});

test('le journal chiffre les dettes ÉCHUES (aging par échéance)', function () {
    // Échu (échéance il y a 5 j), partiellement réglé → reste 60 000 EN RETARD.
    $overdue = draftPurchase($this->provider, 100000, ['due_date' => now()->subDays(5)->toDateString()]);
    $this->put(route('purchases.validate', $overdue));
    $this->post(route('purchases.pay', $overdue), [
        'amount' => 40000, 'method' => 'especes', 'payment_date' => now()->toDateString(),
    ]);

    // À échoir (échéance dans 10 j) → PAS en retard.
    $future = draftPurchase($this->provider, 50000, ['reference' => 'ACH-00002', 'due_date' => now()->addDays(10)->toDateString()]);
    $this->put(route('purchases.validate', $future));

    $stats = $this->get(route('purchases.index'))->assertOk()->viewData('stats');

    expect($stats['overdue'])->toBe(60000.0)
        ->and($stats['overdue_count'])->toBe(1)
        ->and($stats['total_due'])->toBe(110000.0); // 60 000 (échu) + 50 000 (à échoir)
});
