<?php

use App\Actions\EggProduction\RecordEggCollection;
use App\Models\Batch;
use App\Models\ProductionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE TAUX DE PONTE SE RAPPORTE AUX POULES, PAS AUX COQS.
 *
 * Collecte, analyse de ponte, tableau de bord et liste divisaient le nombre
 * d'œufs par l'effectif VIVANT. Pour un lot de reproducteurs — 100 poules et
 * 10 coqs —, 90 œufs faisaient 82 % au lieu de 90 %, et le garde-fou « 1 œuf
 * par sujet et par jour » laissait passer 110 œufs. Une déclaration :
 * Batch::effectifPondeur().
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    $this->seed(Database\Seeders\SpeciesSeeder::class);

    $this->reproducteurs = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'production_type_id' => ProductionType::where('slug', 'reproducteur')
            ->whereHas('species', fn ($q) => $q->where('slug', 'poulet'))->value('id'),
        'qty_males' => 10, 'qty_females' => 100,
        'initial_quantity' => 110, 'current_quantity' => 110,
        'arrival_date' => now()->subWeeks(30)->toDateString(), 'birth_date' => now()->subWeeks(30)->toDateString(),
    ]);
});

test('l’effectif pondeur d’un lot de reproducteurs, ce sont ses poules', function () {
    expect($this->reproducteurs->effectifPondeur())->toBe(100);

    // Un lot de pondeuses sans mâles déclarés reste entier.
    $pondeuses = Batch::factory()->create(['current_quantity' => 500, 'qty_males' => 0, 'qty_females' => 0]);
    expect($pondeuses->effectifPondeur())->toBe(500);
});

test('90 œufs pour 100 poules et 10 coqs : 90 %, pas 82 %', function () {
    $collecte = app(RecordEggCollection::class)->execute([
        'batch_id' => $this->reproducteurs->id, 'production_date' => now()->toDateString(),
        'total_eggs_collected' => 90,
    ]);

    expect((float) $collecte->laying_rate)->toBe(90.0);
});

test('le garde-fou « 1 œuf par poule » ne compte pas les coqs', function () {
    expect(fn () => app(RecordEggCollection::class)->execute([
        'batch_id' => $this->reproducteurs->id, 'production_date' => now()->toDateString(),
        'total_eggs_collected' => 105,
    ]))->toThrow(ValidationException::class);
});
