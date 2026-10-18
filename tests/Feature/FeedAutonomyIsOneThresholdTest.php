<?php

use App\Models\Batch;
use App\Models\ProductionType;
use App\Models\Setting;
use App\Models\Stock;
use App\Services\BatchAdvisorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'AUTONOMIE D'UN ALIMENT SE JUGE AU SEUIL DE LA FERME — SUR CHAQUE ÉCRAN.
 *
 * Le tableau de bord lisait le réglage `stocks.critical_days_threshold`
 * (« critique » à 3 jours ou moins) ; le conseiller du lot et le résumé du
 * matin tenaient le leur en dur (« critique » sous 3 jours, « attention » sous
 * 7). À 3 jours : critique ici, simple attention là ; et une ferme qui
 * relevait son seuil à 5 jours n'était entendue que par un écran.
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
    $this->phase = $this->beliers->feedPhases()[0];
});

/** Un stock de l'aliment du lot couvrant exactement $jours jours. */
function stockPourJours(Tests\TestCase $test, Batch $lot, string $aliment, int $farmId, int $jours): void
{
    Stock::where('item_name', $aliment)->delete();
    $stock = Stock::create(['item_name' => $aliment, 'feed_type' => $aliment, 'category' => 'conso',
        'unit' => 'KG', 'current_quantity' => 1, 'alert_threshold' => 0, 'farm_id' => $farmId]);
    $parJour = app(BatchAdvisorService::class)->feedAutonomy($lot)['daily_kg'];
    $stock->update(['current_quantity' => $parJour * ($jours + 0.5)]);   // demi-journée de marge : daily_kg est arrondi
}

test('à 3 jours, avec le seuil par défaut, le lot est en autonomie CRITIQUE — comme au tableau de bord', function () {
    stockPourJours($this, $this->beliers, $this->phase, $this->farm->id, 3);

    $autonomie = app(BatchAdvisorService::class)->feedAutonomy($this->beliers);
    expect($autonomie['days'])->toBe(3)
        ->and($autonomie['is_critical'])->toBeTrue();
});

test('le seuil réglé par la ferme vaut aussi pour le conseiller du lot', function () {
    Setting::set('stocks.critical_days_threshold', '5');

    expect(Stock::niveauAutonomie(4))->toBe('critique')
        ->and(Stock::niveauAutonomie(9))->toBe('attention')
        ->and(Stock::niveauAutonomie(11))->toBeNull();

    stockPourJours($this, $this->beliers, $this->phase, $this->farm->id, 4);
    expect(app(BatchAdvisorService::class)->feedAutonomy($this->beliers)['is_critical'])->toBeTrue();
});

test('par défaut, « attention » commence toujours à 6 jours', function () {
    expect(Stock::niveauAutonomie(6))->toBe('attention')
        ->and(Stock::niveauAutonomie(7))->toBeNull();
});
