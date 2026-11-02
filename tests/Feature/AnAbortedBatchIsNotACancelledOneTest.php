<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Services\Accounting\PeriodCharges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « ANNULÉ » ET « AVORTÉ » : DEUX NOTIONS, PLUS UN SEUL STATUT.
 *
 * Un lot « Annulé » gardait son achat d'animaux au compte de résultat, et
 * comptait dans les bilans. Or le même statut servait deux réalités opposées.
 * Décision de l'exploitation :
 *
 *   • ANNULÉ (erreur de saisie) — doublon, commande non passée : aucun flux
 *     réel. Exclu de tout (rapports, bilans, compte de résultat), comme une
 *     suppression logique. Réservé à un lot SANS flux enregistré.
 *   • AVORTÉ / SINISTRÉ — reçu ou engagé, cycle arrêté avant terme : ses coûts
 *     sont réels. Son achat paraît sur une ligne dédiée (« Pertes sur lots
 *     avortés / sinistrés ») ; il sort des moyennes zootechniques. Posé par la
 *     clôture.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'initial_quantity' => 100, 'current_quantity' => 100,
        'buy_price_per_unit' => 500, 'total_acquisition_cost' => 50000,
        'arrival_date' => now()->startOfMonth()->toDateString(),
    ]);
});

function chargesDuMoisAvorte(): array
{
    return PeriodCharges::between(now()->startOfMonth(), now()->endOfMonth());
}

function modifierStatutDuLot(Tests\TestCase $test, Batch $lot, string $statut)
{
    return $test->put(route('batches.update', $lot), [
        'type' => 'chair', 'building_id' => $lot->building_id,
        'arrival_date' => $lot->arrival_date->toDateString(),
        'buy_price_per_unit' => $lot->buy_price_per_unit, 'status' => $statut,
    ]);
}

// ── AVORTÉ / SINISTRÉ ────────────────────────────────────────────────────

test('la clôture « avortée » pose le statut Avorté', function () {
    $this->put(route('batches.close', $this->lot), [
        'actual_sell_price_per_unit' => 0, 'closing_date' => now()->toDateString(), 'closure_kind' => 'avorte',
    ])->assertSessionHasNoErrors();

    expect($this->lot->fresh()->status)->toBe('Avorté');
});

test('l’achat d’un lot avorté paraît sur sa ligne dédiée, pas parmi les achats d’animaux', function () {
    $this->lot->update(['status' => Batch::STATUS_AVORTE]);

    $charges = chargesDuMoisAvorte();

    expect($charges['Pertes sur lots avortés / sinistrés'])->toEqual(50000.0)
        ->and($charges['Achats animaux (lots)'])->toEqual(0.0);
});

test('un lot avorté sort de la moyenne zootechnique (GMQ, filtre « tous »)', function () {
    $ruminant = \App\Models\Species::where('family', 'petit_ruminant')->first()
        ?? \App\Models\Species::create(['slug' => 'mouton', 'name_fr' => 'Mouton', 'family' => 'petit_ruminant']);
    $this->lot->update(['species_id' => $ruminant->id, 'status' => Batch::STATUS_AVORTE]);

    $lots = $this->get(route('reports.gmq', ['status' => 'all']))->assertOk()->viewData('batchStats');

    expect(collect($lots)->pluck('batch.id'))->not->toContain($this->lot->id);
});

// ── ANNULÉ (erreur de saisie) ────────────────────────────────────────────

test('un lot annulé sort du compte de résultat et des bilans', function () {
    modifierStatutDuLot($this, $this->lot, 'Annulé')->assertSessionHasNoErrors();

    expect($this->lot->fresh()->status)->toBe('Annulé')
        ->and(chargesDuMoisAvorte()['Achats animaux (lots)'])->toEqual(0.0)
        ->and(Batch::live()->pluck('id'))->not->toContain($this->lot->id);

    $archives = $this->get(route('batches.archives'))->assertOk()->viewData('archivedBatches');
    expect(collect($archives->items())->pluck('id'))->not->toContain($this->lot->id);
});

test('un lot qui a vécu ne s’annule pas : il se clôture comme avorté', function () {
    DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->lot->id,
        'check_date' => today()->toDateString(), 'mortality' => 2,
    ]);

    modifierStatutDuLot($this, $this->lot, 'Annulé')->assertSessionHasErrors('status');

    expect($this->lot->fresh()->status)->toBe('Actif')
        ->and($this->lot->fresh()->statutsModifiables())->not->toContain('Annulé');
});

test('un lot mené à terme garde ses achats parmi les achats d’animaux — non-régression', function () {
    $this->lot->update(['status' => Batch::STATUS_TERMINE]);

    expect(chargesDuMoisAvorte()['Achats animaux (lots)'])->toEqual(50000.0)
        ->and(chargesDuMoisAvorte()['Pertes sur lots avortés / sinistrés'])->toEqual(0.0);
});

test('un lot avorté libère son bâtiment : les jours vides se comptent depuis sa clôture', function () {
    // Une bande menée à terme il y a 40 jours, puis la bande avortée il y a 3 jours.
    Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => Batch::STATUS_TERMINE,
        'arrival_date' => now()->subDays(90)->toDateString(), 'closing_date' => now()->subDays(40)->toDateString(),
        'initial_quantity' => 100, 'current_quantity' => 0,
    ]);
    $this->lot->update(['status' => Batch::STATUS_AVORTE, 'current_quantity' => 0, 'closing_date' => now()->subDays(3)->toDateString()]);

    $occupation = app(\App\Services\PlanningService::class)
        ->getBuildingOccupancy(now()->startOfMonth(), now()->endOfMonth())
        ->firstWhere('building.id', $this->building->id);

    expect($occupation['idle_days'])->toBe(3);
});
