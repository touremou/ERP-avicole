<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\ProductionType;
use App\Models\Species;
use App\Models\TaskAssignment;
use App\Models\TaskTemplate;
use App\Services\TaskSchedulerService;
use Database\Seeders\SpeciesSeeder;
use Illuminate\Support\Carbon;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN MODÈLE DE TÂCHE VISE AUSSI DES FAMILLES D'ESPÈCES.
 *
 * « reproducteur » vaut pour la poule ET le bélier : le planificateur générait
 * « Retournement des œufs en incubation » pour un lot de béliers. Les modèles
 * livrés concernés sont bornés à leur famille (migration), et le planificateur
 * lit cette borne. Sur le modèle livré lui-même (migration de semis).
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(SpeciesSeeder::class);
    $this->retournement = TaskTemplate::withoutGlobalScopes()->where('name', 'Retournement des œufs en incubation')->firstOrFail();
    $this->retournement->update(['is_active' => true, 'frequency' => 'quotidien', 'days_of_week' => [0, 1, 2, 3, 4, 5, 6], 'farm_id' => null]);
});

function batimentAvec(string $espece, string $type): Building
{
    $sp = Species::where('slug', $espece)->first();
    $b = Building::factory()->create(['type' => 'mixte', 'capacity' => 10_000, 'farm_id' => session('current_farm_id'), 'status' => 'Occupé']);
    Batch::factory()->create([
        'building_id' => $b->id, 'species_id' => $sp->id, 'status' => 'Actif', 'current_quantity' => 50,
        'production_type_id' => ProductionType::where('species_id', $sp->id)->where('slug', $type)->value('id'),
    ]);

    return $b;
}

test('le modèle livré « Retournement des œufs » est borné à la volaille', function () {
    expect($this->retournement->species_families)->toBe(['volaille']);
});

test('des BÉLIERS reproducteurs ne reçoivent pas de retournement d’œufs ; des poules, oui', function () {
    $belier = batimentAvec('mouton', 'reproducteur');
    $poules = batimentAvec('poulet', 'reproducteur');

    app(TaskSchedulerService::class)->generateForDate(Carbon::today(), session('current_farm_id'));

    $taches = TaskAssignment::where('task_template_id', $this->retournement->id);
    expect((clone $taches)->where('building_id', $belier->id)->exists())->toBeFalse()
        ->and((clone $taches)->where('building_id', $poules->id)->exists())->toBeTrue();
});
