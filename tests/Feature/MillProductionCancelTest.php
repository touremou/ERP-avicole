<?php

use App\Models\MillMachine;
use App\Models\MillProduction;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/** La machine est-elle engagée sur un OP ouvert (non clôturé/annulé) ? */
function machineBusy(int $machineId): bool
{
    return DB::table('mill_production_machine')
        ->join('mill_productions', 'mill_productions.id', '=', 'mill_production_machine.mill_production_id')
        ->where('mill_production_machine.mill_machine_id', $machineId)
        ->whereNotIn('mill_productions.status', ['Terminé', 'Annulé'])
        ->exists();
}

test('annuler un ordre de production libère la machine engagée', function () {
    $machine = MillMachine::factory()->create(['status' => 'Opérationnel']);
    $prod = MillProduction::factory()->create(['status' => 'Planifié', 'machine_id' => $machine->id]);
    $prod->machines()->attach($machine->id);

    // Avant : la machine est occupée par l'OP planifié.
    expect(machineBusy($machine->id))->toBeTrue();

    $this->put(route('production.cancel', $prod->id))
        ->assertRedirect(route('production.index'))
        ->assertSessionHas('success');

    // Après : OP annulé → machine libérée.
    expect($prod->fresh()->status)->toBe('Annulé')
        ->and(machineBusy($machine->id))->toBeFalse();
});

test('on ne peut pas annuler un ordre déjà clôturé', function () {
    $machine = MillMachine::factory()->create(['status' => 'Opérationnel']);
    $prod = MillProduction::factory()->create(['status' => 'Terminé', 'machine_id' => $machine->id]);

    $this->put(route('production.cancel', $prod->id))->assertSessionHas('error');

    expect($prod->fresh()->status)->toBe('Terminé');
});
