<?php

use App\Models\Batch;
use App\Models\PlannedBatch;
use App\Models\ProductionType;
use App\Models\SlaughterOrder;
use App\Services\SlaughterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN LOT SE CLÔTURE PAR SA CLÔTURE — PAS PAR TROIS AUTRES PORTES.
 *
 * Quatre gestes passaient un lot « Terminé » :
 *
 *   • la CLÔTURE (CloseBatch) : bilan, marge enregistrée, effectif à zéro,
 *     vide sanitaire du bâtiment ;
 *   • le formulaire de MODIFICATION, qui proposait « Terminé » dans la liste
 *     des statuts ;
 *   • le PLANNING, dont la bande passée « terminée » fermait son lot ;
 *   • l'ABATTAGE de tout le lot.
 *
 * Les trois derniers ne posaient que le statut : pas de marge, des sujets
 * encore comptés vivants dans un lot fermé, un bâtiment jamais mis en vide
 * sanitaire — donc jamais proposé, ni signalé, pour la bande suivante.
 *
 * Décision de l'exploitation : la modification et le planning refusent
 * « Terminé » et renvoient à la clôture ; l'abattage complet appelle la
 * clôture lui-même. La clôture, elle, passe la bande planifiée liée à
 * « terminée » : le planning suit le lot, au lieu de le fermer à sa place.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif', 'type' => 'chair',
        'initial_quantity' => 100, 'current_quantity' => 100, 'qty_dead' => 0,
        'buy_price_per_unit' => 500, 'total_acquisition_cost' => 50000, 'additional_costs' => 0,
        'arrival_date' => now()->subDays(42)->toDateString(),
    ]);
});

function modifierLeLot(Tests\TestCase $test, Batch $lot, string $statut)
{
    return $test->put(route('batches.update', $lot), [
        'type' => 'chair', 'building_id' => $lot->building_id,
        'arrival_date' => $lot->arrival_date->toDateString(),
        'buy_price_per_unit' => $lot->buy_price_per_unit, 'status' => $statut,
    ]);
}

// ── MODIFICATION ─────────────────────────────────────────────────────────

test('la modification ne clôture plus un lot', function () {
    modifierLeLot($this, $this->lot, 'Terminé')->assertSessionHasErrors('status');

    expect($this->lot->fresh()->status)->toBe('Actif');
});

test('la modification ne rouvre pas non plus un lot clôturé', function () {
    $this->lot->update(['status' => 'Terminé']);

    modifierLeLot($this, $this->lot->fresh(), 'Actif')->assertSessionHasErrors('status');

    expect($this->lot->fresh()->status)->toBe('Terminé');
});

test('le formulaire ne propose que les statuts qu’il peut poser', function () {
    expect($this->lot->statutsModifiables())->not->toContain('Terminé')
        ->and($this->lot->statutsModifiables())->toContain('Actif');

    $this->get(route('batches.edit', $this->lot))->assertOk()
        ->assertDontSee('<option value="Terminé"', false);
});

test('modifier un lot sans toucher à son statut reste possible — non-régression', function () {
    modifierLeLot($this, $this->lot, 'Actif')->assertSessionHasNoErrors();

    $this->lot->update(['status' => 'Terminé']);
    modifierLeLot($this, $this->lot->fresh(), 'Terminé')->assertSessionHasNoErrors();
});

// ── PLANNING ─────────────────────────────────────────────────────────────

function bandeEnCours(int $ferme, Batch $lot, int $createur): PlannedBatch
{
    return PlannedBatch::create([
        'farm_id' => $ferme, 'building_id' => $lot->building_id, 'batch_type' => 'chair',
        'production_type_id' => ProductionType::resolveOrCreate('chair', null)->id,
        'planned_quantity' => 100, 'planned_arrival_date' => now()->subDays(42)->toDateString(),
        'planned_end_date' => now()->addDays(3)->toDateString(),
        'chick_order_deadline' => now()->subDays(90)->toDateString(),
        'status' => 'en_cours', 'actual_batch_id' => $lot->id, 'created_by' => $createur,
    ]);
}

test('le planning ne ferme plus le lot à la place de la clôture', function () {
    $bande = bandeEnCours($this->farm->id, $this->lot, $this->adminUser->id);

    $this->put(route('planning.status', $bande), ['status' => 'termine'])
        ->assertSessionHas('error');

    expect($this->lot->fresh()->status)->toBe('Actif')
        ->and($bande->fresh()->status)->toBe('en_cours');
});

test('la clôture du lot termine la bande planifiée', function () {
    $bande = bandeEnCours($this->farm->id, $this->lot, $this->adminUser->id);

    $this->put(route('batches.close', $this->lot), [
        'actual_sell_price_per_unit' => 2500, 'closing_date' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect($this->lot->fresh()->status)->toBe('Terminé')
        ->and($bande->fresh()->status)->toBe('termine');
});

// ── ABATTAGE COMPLET ─────────────────────────────────────────────────────

test('abattre tout le lot le clôture par la clôture : marge, effectif, vide sanitaire', function () {
    $ordre = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $this->lot->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 100,
        'status' => 'planifie', 'requested_by' => $this->adminUser->id,
    ]);

    app(SlaughterService::class)->executeSlaughter($ordre, [
        'actual_quantity' => 100, 'total_live_weight_kg' => 220,
        'total_carcass_weight_kg' => 150, 'execution_date' => now()->toDateString(),
    ]);

    $lot = $this->lot->fresh();
    expect($lot->status)->toBe('Terminé')
        ->and((int) $lot->current_quantity)->toBe(0)
        ->and($lot->closing_date)->not->toBeNull()
        // Cédé au coût de revient : la marge enregistrée existe, et vaut zéro.
        ->and($lot->margin)->not->toBeNull()
        ->and(round((float) $lot->margin, 2))->toEqual(0.0)
        ->and($this->building->fresh()->status)->toBe(\App\Models\Building::STATUS_DESINFECTION);
});

test('un abattage partiel laisse le lot ouvert — non-régression', function () {
    $ordre = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $this->lot->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 40,
        'status' => 'planifie', 'requested_by' => $this->adminUser->id,
    ]);

    app(SlaughterService::class)->executeSlaughter($ordre, [
        'actual_quantity' => 40, 'total_live_weight_kg' => 88,
        'total_carcass_weight_kg' => 60, 'execution_date' => now()->toDateString(),
    ]);

    $lot = $this->lot->fresh();
    expect($lot->status)->toBe('Actif')
        ->and((int) $lot->current_quantity)->toBe(60);
});

test('rouvrir le lot rouvre sa bande planifiée', function () {
    $bande = bandeEnCours($this->farm->id, $this->lot, $this->adminUser->id);
    app(\App\Actions\Batch\CloseBatch::class)->execute($this->lot->fresh(), [
        'actual_sell_price_per_unit' => 2500, 'closing_date' => now()->toDateString(),
    ]);
    expect($bande->fresh()->status)->toBe('termine');

    app(\App\Actions\Batch\ReopenBatch::class)->execute($this->lot->fresh());

    expect($this->lot->fresh()->status)->toBe('Actif')
        ->and($bande->fresh()->status)->toBe('en_cours');
});
