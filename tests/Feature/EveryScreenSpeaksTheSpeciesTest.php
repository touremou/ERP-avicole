<?php

use App\Models\Batch;
use App\Models\PlannedBatch;
use App\Models\ProductionType;
use App\Models\Species;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN ÉCRAN COMMUN PARLE LA LANGUE DE L'ESPÈCE.
 *
 * Signalé par l'exploitation, capture à l'appui : la clôture d'un bassin de
 * tilapias annonçait « Acquisition poussins » et « Vente volaille ». Le
 * planning, commun à toutes les espèces, disait « Poussins commandés » pour
 * des porcs. Une déclaration : Species::jeunes() / vente(), et
 * Batch::libelleAcquisition() pour distinguer des jeunes d'animaux faits.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    $this->seed(Database\Seeders\SpeciesSeeder::class);
});

function lotEspece(int $ferme, int $batiment, string $espece, string $type, array $champs = []): Batch
{
    $sp = Species::where('slug', $espece)->first();

    return Batch::factory()->create(array_merge([
        'farm_id' => $ferme, 'building_id' => $batiment, 'status' => 'Actif',
        'species_id' => $sp->id, 'type' => $type,
        'production_type_id' => ProductionType::where('slug', $type)->where('species_id', $sp->id)->value('id'),
        'initial_quantity' => 2500, 'current_quantity' => 2500, 'buy_price_per_unit' => 500,
        'arrival_date' => now()->subDays(2)->toDateString(),
    ], $champs));
}

test('la clôture d’un bassin de tilapias parle d’alevins et de poissons', function () {
    $tilapias = lotEspece($this->farm->id, $this->building->id, 'tilapia', 'grossissement');

    $this->get(route('batches.close_form', $tilapias))->assertOk()
        ->assertSee('Acquisition alevins')
        ->assertSee('Vente poissons')
        ->assertDontSee('poussins')
        ->assertDontSee('Vente volaille');
});

test('la clôture d’une bande de poulets garde ses mots', function () {
    $poulets = lotEspece($this->farm->id, $this->building->id, 'poulet', 'chair');

    $this->get(route('batches.close_form', $poulets))->assertOk()
        ->assertSee('Acquisition poussins')
        ->assertSee('Vente volaille');
});

test('des reproducteurs achetés adultes ne sont pas des « jeunes »', function () {
    $beliers = lotEspece($this->farm->id, $this->building->id, 'mouton', 'reproducteur', ['qty_males' => 2, 'qty_females' => 50]);

    expect($beliers->libelleAcquisition())->toBe('animaux')
        ->and($beliers->libelleVente())->toBe('ovins');

    // Agneaux reçus à 3 semaines : des jeunes ; à 6 mois : des animaux faits.
    $agneaux = lotEspece($this->farm->id, $this->building->id, 'mouton', 'engraissement', ['birth_date' => now()->subDays(23)->toDateString()]);
    $antenais = lotEspece($this->farm->id, $this->building->id, 'mouton', 'engraissement', ['birth_date' => now()->subDays(182)->toDateString()]);
    expect($agneaux->libelleAcquisition())->toBe('agneaux')
        ->and($antenais->libelleAcquisition())->toBe('animaux');
});

test('le planning d’une bande de porcs commande des porcelets, pas des poussins', function () {
    $porc = Species::where('slug', 'porc')->first();
    $plan = PlannedBatch::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'batch_type' => 'engraissement',
        'species_id' => $porc->id, 'production_type_id' => ProductionType::where('slug', 'engraissement')->where('species_id', $porc->id)->value('id'),
        'planned_quantity' => 40, 'planned_arrival_date' => now()->addDays(60)->toDateString(),
        'planned_end_date' => now()->addDays(228)->toDateString(), 'chick_order_deadline' => now()->addDays(4)->toDateString(),
        'status' => 'commande', 'created_by' => $this->adminUser->id,
    ]);

    $this->get(route('planning.show', $plan))->assertOk()
        ->assertSee('Porcelets commandés')
        ->assertDontSee('Poussins');
});
