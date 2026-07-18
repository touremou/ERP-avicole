<?php

use App\Actions\Slaughter\RecordSlaughterReception;
use App\Models\Expense;
use App\Models\Provider;
use App\Models\SlaughterReception;
use App\Models\SupplierInvoice;
use App\Services\Accounting\SyscohadaMapper;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Achat vif (E8) : une réception « achat » avec prix génère une facture
 * fournisseur brouillon (dette envers l'éleveur) qui, une fois validée, poste
 * la charge au P&L (SYSCOHADA 602). Le façon et l'achat sans prix n'engendrent
 * aucune facture.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
    $this->provider = Provider::factory()->create(['name' => 'Éleveur BioCrest']);
});

function recordReception(int $providerId, int $controllerId, array $overrides = []): SlaughterReception
{
    return app(RecordSlaughterReception::class)->execute(array_merge([
        'provider_id'          => $providerId,
        'origin'               => 'achat',
        'reception_date'       => now()->toDateString(),
        'received_quantity'    => 20,
        'rejected_quantity'    => 0,
        'total_live_weight_kg' => 40,
        'sanitary_state'       => 'conforme',
        'fasting_respected'    => 'oui',
        'decision'             => 'accepte',
        'controller_id'        => $controllerId,
    ], $overrides));
}

test('un achat vif au prix par sujet génère une facture fournisseur brouillon (dette)', function () {
    $reception = recordReception($this->provider->id, $this->adminUser->id, [
        'purchase_basis'      => 'par_sujet',
        'purchase_unit_price' => 3000,   // 20 × 3000 = 60 000
    ]);

    expect((float) $reception->purchase_total_cost)->toBe(60000.0)
        ->and($reception->supplier_invoice_id)->not->toBeNull();

    $invoice = SupplierInvoice::find($reception->supplier_invoice_id);
    expect($invoice->status)->toBe('brouillon')
        ->and($invoice->category)->toBe('achat_animaux')
        ->and((float) $invoice->total_amount)->toBe(60000.0)
        ->and($invoice->provider_id)->toBe($this->provider->id);
});

test('un achat vif au kg vif calcule le coût sur la pesée', function () {
    $reception = recordReception($this->provider->id, $this->adminUser->id, [
        'total_live_weight_kg' => 50,
        'purchase_basis'       => 'par_kg_vif',
        'purchase_unit_price'  => 2000,  // 50 × 2000 = 100 000
    ]);

    expect((float) $reception->purchase_total_cost)->toBe(100000.0);
    expect((float) SupplierInvoice::find($reception->supplier_invoice_id)->total_amount)->toBe(100000.0);
});

test('la facture d\'achat vif, une fois validée, poste une dépense « achat animaux » (charge P&L)', function () {
    $reception = recordReception($this->provider->id, $this->adminUser->id, [
        'purchase_basis'      => 'forfait',
        'purchase_unit_price' => 75000,
    ]);
    $invoice = SupplierInvoice::find($reception->supplier_invoice_id);

    $this->put(route('purchases.validate', $invoice->id))->assertRedirect();

    $expense = Expense::where('category', 'achat_animaux')->first();
    expect($expense)->not->toBeNull()
        ->and($expense->status)->toBe('valide')
        ->and((float) $expense->amount)->toBe(75000.0);
});

test('un achat vif SANS prix ne génère aucune facture (saisie au bureau plus tard)', function () {
    $reception = recordReception($this->provider->id, $this->adminUser->id); // pas de purchase_unit_price

    expect($reception->purchase_total_cost)->toBeNull()
        ->and($reception->supplier_invoice_id)->toBeNull()
        ->and(SupplierInvoice::count())->toBe(0);
});

test('un abattage à façon ne génère jamais de facture d\'achat', function () {
    $reception = recordReception($this->provider->id, $this->adminUser->id, [
        'origin'              => 'facon',
        'purchase_basis'      => 'par_sujet',
        'purchase_unit_price' => 3000, // ignoré : ce n'est pas un achat
    ]);

    expect($reception->supplier_invoice_id)->toBeNull()
        ->and(SupplierInvoice::count())->toBe(0);
});

test('la catégorie achat_animaux est rattachée au compte SYSCOHADA 602', function () {
    $mapper = new SyscohadaMapper();
    $label = 'Dépenses : ' . Expense::CATEGORIES['achat_animaux'];

    expect($mapper->chargeAccount($label)[0])->toBe('602');
});
