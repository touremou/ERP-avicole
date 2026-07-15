<?php

use App\Models\Batch;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\PayrollPeriod;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Audit 360 §2.1 (W1-W3) — vague 2 : lot (clôture/réouverture), paie,
 * achats fournisseurs, caisse. Toute transition illégale est rejetée côté
 * serveur, proprement (message flash, jamais un 500), sans effet de bord.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
});

// ─── LOT : CLÔTURE / RÉOUVERTURE ───

test('re-clôturer un lot Terminé est refusé et n\'écrase pas la marge historique', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Terminé',
        'closing_date'     => now()->subDay(),
        'margin'           => 123456,
        'current_quantity' => 0,
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('batches.close', $batch), [
            'actual_sell_price_per_unit' => 99999,
            'closing_date'               => now()->toDateString(),
        ])
        ->assertSessionHas('error');

    $fresh = $batch->fresh();
    expect($fresh->status)->toBe('Terminé');
    expect((float) $fresh->margin)->toEqual(123456.0); // marge historique intacte
});

test('rouvrir un lot ACTIF est refusé proprement (message, pas un 500)', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 400,
    ]);

    $this->actingAs($this->adminUser)
        ->put(route('batches.reopen', $batch))
        ->assertSessionHas('error');

    expect($batch->fresh()->status)->toBe('Actif');
});

test('rouvrir un lot Terminé (droit S) le repasse Actif avec effectif recalculé', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Terminé',
        'closing_date'     => now()->subDay(),
        'initial_quantity' => 800,
        'current_quantity' => 0,
    ]);

    $this->actingAs($this->adminUser)
        ->put(route('batches.reopen', $batch))
        ->assertSessionHas('success');

    $fresh = $batch->fresh();
    expect($fresh->status)->toBe('Actif');
    expect($fresh->closing_date)->toBeNull();
    expect($fresh->current_quantity)->toBeGreaterThan(0); // recalcul depuis les pointages
});

// ─── PAIE ───

/** Insère une période de paie au statut voulu (colonnes réelles du schéma). */
function makePayrollPeriod(int $farmId, string $status): PayrollPeriod
{
    $id = DB::table('payroll_periods')->insertGetId([
        'farm_id'    => $farmId,
        'label'      => 'Juin 2026',
        'year'       => 2026,
        'month'      => 6,
        'start_date' => '2026-06-01',
        'end_date'   => '2026-06-30',
        'status'     => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return PayrollPeriod::withoutGlobalScopes()->findOrFail($id);
}

test('valider une période de paie jamais calculée (brouillon) est refusé', function () {
    $period = makePayrollPeriod($this->farm->id,'brouillon');

    $this->actingAs($this->adminUser)
        ->post(route('payroll.validate', $period))
        ->assertSessionHas('error');

    $fresh = $period->fresh();
    expect($fresh->status)->toBe('brouillon');
    expect($fresh->validated_at)->toBeNull();
});

test('re-valider une période déjà validée est refusé (validation non ré-horodatée)', function () {
    $period = makePayrollPeriod($this->farm->id,'calcule');

    $this->actingAs($this->adminUser)
        ->post(route('payroll.validate', $period))
        ->assertSessionHas('success');

    $validatedAt = $period->fresh()->validated_at;
    expect($period->fresh()->status)->toBe('valide');

    $this->actingAs($this->adminUser)
        ->post(route('payroll.validate', $period))
        ->assertSessionHas('error');

    expect($period->fresh()->status)->toBe('valide');
    expect($period->fresh()->validated_at?->toIso8601String())
        ->toBe($validatedAt?->toIso8601String());
});

test('générer les fiches d\'une période PAYÉE est refusé (période verrouillée)', function () {
    $period = makePayrollPeriod($this->farm->id,'paye');

    $this->actingAs($this->managerUser)
        ->post(route('payroll.generate', $period))
        ->assertSessionHas('error');

    expect($period->fresh()->status)->toBe('paye');
});

// ─── ACHATS FOURNISSEURS (invariant AP : UNE dépense par achat validé) ───

test('valider deux fois un achat : le second appel est refusé, UNE seule dépense au P&L', function () {
    $this->actingAs($this->adminUser);

    $invoice = SupplierInvoice::create([
        'provider_id'  => $this->provider->id,
        'reference'    => 'ACH-W2-001',
        'invoice_date' => now()->toDateString(),
        'category'     => 'fournitures',
        'label'        => 'Achat vague 2',
        'total_amount' => 200000,
        'status'       => 'brouillon',
        'user_id'      => Auth::id(),
    ]);

    $this->put(route('purchases.validate', $invoice))->assertSessionHas('success');
    expect($invoice->fresh()->status)->toBe('valide');
    expect(Expense::count())->toBe(1);

    $this->put(route('purchases.validate', $invoice))->assertSessionHas('error');

    expect($invoice->fresh()->status)->toBe('valide');
    expect(Expense::count())->toBe(1); // jamais deux imputations
});

// ─── CAISSE ───

test('la caisse refuse une double ouverture et une double clôture', function () {
    // Compte caisse actif : cible du fallback d'ouverture.
    $account = [
        'name'            => 'Caisse principale',
        'type'            => 'caisse',
        'current_balance' => 0,
        'is_active'       => true,
        'created_at'      => now(),
        'updated_at'      => now(),
    ];
    if (Schema::hasColumn('treasury_accounts', 'farm_id')) {
        $account['farm_id'] = $this->farm->id;
    }
    DB::table('treasury_accounts')->insert($account);

    $this->actingAs($this->managerUser)
        ->post(route('cash-register.open'), ['opening_float' => 40000])
        ->assertSessionHas('success');

    // Double ouverture → refus explicite.
    $this->actingAs($this->managerUser)
        ->post(route('cash-register.open'), ['opening_float' => 50000])
        ->assertSessionHas('error');

    $session = CashRegisterSession::query()->latest('id')->firstOrFail();

    // Comptage JUSTE (2 × 20 000 = fond de caisse) : un écart flasherait
    // volontairement « error » (règle métier), ce n'est pas le sujet ici.
    $this->actingAs($this->managerUser)
        ->post(route('cash-register.close', $session), ['counts' => ['20000' => 2]])
        ->assertSessionHas('success');

    // Double clôture → refus explicite.
    $this->actingAs($this->managerUser)
        ->post(route('cash-register.close', $session), ['counts' => []])
        ->assertSessionHas('error');

    expect(CashRegisterSession::query()->count())->toBe(1);
});
