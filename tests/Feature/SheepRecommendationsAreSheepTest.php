<?php

use App\Models\Batch;
use App\Models\ProductionType;
use App\Models\Stock;
use App\Services\BatchAdvisorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « LES RECOMMANDATIONS SONT-ELLES OK PAR RAPPORT À L'ESPÈCE ? »
 *
 * Question de l'exploitation, sur une bande de béliers Djallonké. Ce qui ne
 * l'était pas :
 *   • l'AUTONOMIE STOCK additionnait tout l'aliment « conso » de la ferme —
 *     celui des poulets compris : « 115 jours » pour des moutons nourris… au
 *     démarrage chair ;
 *   • la norme du bélier adulte était étiquetée « Croissance » ;
 *   • « 35,000 g » et « 1,472 g/sujet » pour un ruminant.
 * L'eau (5,3 L/tête) et le seuil de stress thermique (ruminants : THI 27)
 * étaient, eux, justes.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);
    $this->seed(Database\Seeders\SpeciesSeeder::class);
    $this->seed(Database\Seeders\ProductionNormSeeder::class);

    $type = ProductionType::where('slug', 'reproducteur')
        ->whereHas('species', fn ($q) => $q->where('slug', 'mouton'))->firstOrFail();

    $this->beliers = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'production_type_id' => $type->id, 'type' => 'reproducteur', 'model_name' => 'Bélier Djallonké',
        'arrival_date' => today()->subDays(3), 'birth_date' => today()->subYears(2),
        'status' => 'Actif', 'initial_quantity' => 20, 'current_quantity' => 20,
    ]);
});

test('l’autonomie d’une bande de moutons ne compte pas l’aliment des poulets', function () {
    Stock::create(['item_name' => 'Chair Démarrage', 'feed_type' => 'Chair Démarrage', 'category' => 'conso',
        'unit' => 'KG', 'current_quantity' => 3000, 'alert_threshold' => 100, 'farm_id' => $this->farm->id]);

    expect(app(BatchAdvisorService::class)->feedAutonomy($this->beliers))->toBeNull();

    // Un aliment de SON secteur, lui, compte.
    $phase = $this->beliers->feedPhases()[0];
    Stock::create(['item_name' => $phase, 'feed_type' => $phase, 'category' => 'conso',
        'unit' => 'KG', 'current_quantity' => 600, 'alert_threshold' => 100, 'farm_id' => $this->farm->id]);

    expect(app(BatchAdvisorService::class)->feedAutonomy($this->beliers)['stock_kg'])->toBe(600.0);
});

test('un bélier adulte est à l’entretien, et ses poids se lisent en kilos', function () {
    $reco = app(BatchAdvisorService::class)->recommendation($this->beliers);
    expect($reco['phase'])->toBe('Entretien');

    $page = $this->get(route('batches.show', $this->beliers))->assertOk()->getContent();
    expect($page)->not->toContain('35,000')
        ->and($page)->toContain('· Entretien');
});

test('la reprise réétiquette les normes des reproducteurs adultes déjà en base', function () {
    DB::table('production_norms')->where('model_name', 'Bélier Djallonké')->update(['phase_name' => 'Croissance']);

    (require database_path('migrations/2026_10_13_000002_adult_breeder_norms_are_maintenance.php'))->up();

    expect(DB::table('production_norms')->where('model_name', 'Bélier Djallonké')->value('phase_name'))->toBe('Entretien');
});

test('la création de bande ne fait pas naître des béliers le jour de leur arrivée', function () {
    $page = $this->get(route('batches.create'))->assertOk()->getContent();

    // Les familles sont posées sur les espèces, et la consigne d'âge existe.
    expect($page)->toContain('data-family="petit_ruminant"')
        ->and($page)->toContain('id="birth_hint_age"');
});
