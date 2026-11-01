<?php

use App\Actions\Batch\CloseBatch;
use App\Actions\DailyCheck\RecordDailyCheck;
use App\Actions\Expense\CreateExpense;
use App\Models\Batch;
use App\Models\Expense;
use App\Models\PlannedBatch;
use App\Models\ProductionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN LOT VIDE N'EST PAS UN LOT CLÔTURÉ — FERMETURE EN DEUX TEMPS.
 *
 * Décision de l'exploitation. Clôturer dès que l'effectif tombe à zéro pose
 * problème : des coûts arrivent APRÈS le départ du dernier sujet (carburant,
 * désinfection, vide sanitaire, analyses, aliment livré en fin de bande).
 *
 *   1. ÉTAT OPÉRATIONNEL, automatique : effectif à zéro — abattage, vente
 *      totale, mortalité totale — → « Soldé ». Le lot quitte les lots vivants,
 *      et les événements d'élevage (pointage, pesée, collecte) y sont bloqués ;
 *      les coûts, eux, s'y imputent encore. S'il retrouve des sujets (vente
 *      annulée, retour), il redevient « Actif ».
 *   2. CLÔTURE DÉFINITIVE, par le formulaire : bilan, marge, vide sanitaire —
 *      et verrou contre toute imputation.
 *
 * Avant : un lot vidé par une vente ou par la mortalité restait « Actif » à
 * zéro sujet, compté parmi les lots vivants ; un lot abattu en entier était
 * clôturé d'office, sans place pour ses derniers coûts.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'initial_quantity' => 100, 'current_quantity' => 100, 'qty_dead' => 0,
        'arrival_date' => today()->subDays(30), 'buy_price_per_unit' => 500,
    ]);
});

function depenseSurCeLot(int $lot, int $montant = 15000): Expense
{
    return app(CreateExpense::class)->execute([
        'batch_id' => $lot, 'category' => array_key_first(Expense::CATEGORIES), 'label' => 'Désinfection',
        'amount' => $montant, 'expense_date' => today()->toDateString(),
        'payment_method' => array_key_first(Expense::PAYMENT_METHODS),
    ]);
}

test('une VENTE TOTALE solde le lot ; l’annuler le rend actif', function () {
    // Le chemin de ValidateSale (décrément du lot) et de CancelSale (incrément).
    $this->lot->decrement('current_quantity', 100);
    expect($this->lot->fresh()->status)->toBe('Soldé');

    $this->lot->fresh()->increment('current_quantity', 30);
    expect($this->lot->fresh()->status)->toBe('Actif');
});

test('une MORTALITÉ TOTALE au pointage solde le lot', function () {
    app(RecordDailyCheck::class)->execute([
        'batch_id' => $this->lot->id, 'check_date' => today()->toDateString(),
        'mortality' => 100, 'feed_consumed' => 0, 'feed_type' => '',
    ]);

    expect($this->lot->fresh()->status)->toBe('Soldé')
        ->and(Batch::active()->pluck('id'))->not->toContain($this->lot->id);
});

test('un lot soldé ne reçoit plus d’événement d’élevage', function () {
    $this->lot->decrement('current_quantity', 100);

    expect(fn () => app(RecordDailyCheck::class)->execute([
        'batch_id' => $this->lot->id, 'check_date' => today()->toDateString(),
        'mortality' => 0, 'feed_consumed' => 0, 'feed_type' => '',
    ]))->toThrow(ValidationException::class);
});

test('un lot soldé reçoit encore ses derniers coûts ; la clôture définitive les verrouille', function () {
    $this->lot->decrement('current_quantity', 100);

    // Désinfection payée après le départ du dernier sujet : imputée au lot.
    expect(depenseSurCeLot($this->lot->id)->batch_id)->toBe($this->lot->id);

    app(CloseBatch::class)->execute($this->lot->fresh(), [
        'actual_sell_price_per_unit' => 0, 'closing_date' => today()->toDateString(),
    ]);
    expect($this->lot->fresh()->status)->toBe('Terminé');

    expect(fn () => depenseSurCeLot($this->lot->id))->toThrow(ValidationException::class);
});

test('les dépenses proposent les lots soldés, pas les lots clôturés', function () {
    $this->lot->decrement('current_quantity', 100);
    $clos = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Terminé',
        'initial_quantity' => 50, 'current_quantity' => 0,
    ]);

    $proposes = $this->get(route('expenses.create'))->assertOk()->viewData('batches')->pluck('id');

    expect($proposes)->toContain($this->lot->id)->not->toContain($clos->id);
});

test('la liste des lots rappelle les lots soldés à clôturer', function () {
    $this->lot->decrement('current_quantity', 100);

    $this->get(route('batches.index'))->assertOk()
        ->assertSee('Lots soldés à clôturer')
        ->assertSee($this->lot->code);
});

test('le planning ne termine pas une bande dont le lot n’est que soldé', function () {
    $bande = PlannedBatch::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'batch_type' => 'chair',
        'production_type_id' => ProductionType::resolveOrCreate('chair', null)->id,
        'planned_quantity' => 100, 'planned_arrival_date' => today()->subDays(30)->toDateString(),
        'planned_end_date' => today()->addDays(15)->toDateString(),
        'chick_order_deadline' => today()->subDays(90)->toDateString(),
        'status' => 'en_cours', 'actual_batch_id' => $this->lot->id, 'created_by' => $this->adminUser->id,
    ]);
    $this->lot->decrement('current_quantity', 100);

    $this->put(route('planning.status', $bande), ['status' => 'termine'])->assertSessionHas('error');

    expect($bande->fresh()->status)->toBe('en_cours');
});

test('un lot virtuel à effectif nul n’est jamais soldé', function () {
    $virtuel = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'initial_quantity' => 0, 'current_quantity' => 0,
    ]);
    $virtuel->update(['observations' => 'œufs externes']);
    $virtuel->constaterEtatOperationnel();

    expect($virtuel->fresh()->status)->toBe('Actif');
});
