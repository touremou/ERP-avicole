<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
use App\Models\PlannedBatch;
use App\Models\ProductionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE BANDE PLANIFIÉE NAISSAIT AVEC D'AUTRES EFFECTIFS QU'UNE BANDE SAISIE.
 *
 * Trois portes créent un lot : le formulaire du bureau, la mise en lot du
 * terrain (toutes deux par `CreateBatch`) et l'activation d'une bande planifiée,
 * qui écrivait le lot elle-même, avec sa propre convention :
 *
 *   • l'EFFECTIF INITIAL comptait les morts du transport. `CreateBatch` y met
 *     les vivants reçus et range les morts à part (`qty_dead`). Or la mortalité
 *     se calcule sur « initial + morts du transport » : la bande planifiée
 *     comptait ses morts DEUX fois au dénominateur, et son taux sortait sous-évalué ;
 *   • le COÛT D'ACQUISITION portait sur les livrés, quand les deux autres
 *     portes le portent sur les vivants reçus ;
 *   • aucune CAPACITÉ n'était contrôlée : 600 sujets entraient dans un
 *     bâtiment de 500 places.
 *
 * Mesuré — 290 vivants et 10 morts au transport, à 5 000 GNF :
 *   bande saisie   : initial 290, mortalité 3,33 %, coût 1 450 000 ;
 *   bande planifiée : initial 300, mortalité 3,23 %, coût 1 500 000.
 *
 * L'activation passe désormais par `CreateBatch`.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $this->poulailler = Building::create([
        'farm_id' => $this->farm->id, 'name' => 'B-PLAN', 'type' => 'chair',
        'status' => Building::STATUS_VIDE, 'capacity' => 500,
    ]);
    $this->responsable = Employee::factory()->create(['farm_id' => $this->farm->id, 'hire_date' => today()->subYear()]);

    $this->plan = PlannedBatch::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->poulailler->id, 'batch_type' => 'chair',
        'production_type_id' => ProductionType::resolveOrCreate('chair', null)->id,
        'planned_quantity' => 300, 'planned_arrival_date' => today()->toDateString(),
        'planned_end_date' => today()->addDays(45)->toDateString(),
        'chick_order_deadline' => today()->subDays(30)->toDateString(),
        'status' => 'commande', 'created_by' => $this->adminUser->id,
    ]);
});

function activerLaBande(Tests\TestCase $test, PlannedBatch $plan, int $employe, array $champs = [])
{
    return $test->put(route('planning.status', $plan), array_merge([
        'status' => 'en_cours', 'qty_alive' => 290, 'qty_dead' => 10,
        'buy_price_per_unit' => 5000, 'employee_id' => $employe,
    ], $champs));
}

test('la bande planifiée naît avec les effectifs d’une bande saisie', function () {
    activerLaBande($this, $this->plan, $this->responsable->id)->assertSessionHasNoErrors();

    $lot = Batch::findOrFail($this->plan->fresh()->actual_batch_id);

    expect((int) $lot->initial_quantity)->toBe(290)
        ->and((int) $lot->current_quantity)->toBe(290)
        ->and((int) $lot->qty_dead)->toBe(10)
        ->and((float) $lot->mortality_rate)->toBe(3.33)
        ->and((float) $lot->total_acquisition_cost)->toBe(1450000.0)
        ->and($this->poulailler->fresh()->status)->not->toBe(Building::STATUS_VIDE);
});

test('la capacité du bâtiment est contrôlée, comme pour toute mise en lot', function () {
    activerLaBande($this, $this->plan, $this->responsable->id, ['qty_alive' => 600, 'qty_dead' => 0])
        ->assertSessionHasErrors('building_id');

    expect($this->plan->fresh()->actual_batch_id)->toBeNull()
        ->and($this->plan->fresh()->status)->toBe('commande')
        ->and(Batch::count())->toBe(0);
});
