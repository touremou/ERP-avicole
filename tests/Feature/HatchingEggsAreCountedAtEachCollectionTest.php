<?php

use App\Actions\EggProduction\RecordEggCollection;
use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\ProductionType;
use App\Services\EggAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * ŒUFS INCUBABLES : CALCULÉS À CHAQUE COLLECTE, AVEC UN AJUSTEMENT.
 *
 * Décision de l'exploitation : incubables = collectés − cassés − anormaux −
 * autres déclassés. Le dernier terme est un AJUSTEMENT facultatif (deux
 * jaunes, hors poids, déformés…) pour rectifier ce qui part réellement au
 * couvoir. La colonne existait, aucune saisie ne l'écrivait : 0 partout.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    \Illuminate\Support\Facades\DB::table('farm_user')->updateOrInsert(
        ['farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id],
        ['is_default' => true, 'is_owner' => true, 'created_at' => now(), 'updated_at' => now()],
    );
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $this->pondeuses = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'production_type_id' => ProductionType::resolveOrCreate('ponte', null)->id,
        'arrival_date' => today()->subDays(200), 'birth_date' => today()->subDays(200),
        'initial_quantity' => 500, 'current_quantity' => 500,
    ]);
});

function collecter(Batch $lot, array $saisie): EggProduction
{
    return app(RecordEggCollection::class)->execute(array_merge([
        'batch_id' => $lot->id, 'production_date' => today()->toDateString(),
    ], $saisie));
}

test('chaque passage pose ses incubables, ajustement compris, et ils se cumulent', function () {
    $collecte = collecter($this->pondeuses, ['total_eggs_collected' => 100, 'broken_eggs' => 3, 'small_eggs' => 2]);
    expect($collecte->incubable_eggs)->toBe(95);

    $collecte = collecter($this->pondeuses, ['total_eggs_collected' => 50, 'downgraded_eggs' => 4]);

    // 150 − 3 cassés − 2 anormaux − 4 déclassés.
    expect($collecte->fresh()->incubable_eggs)->toBe(141)
        ->and($collecte->fresh()->downgraded_eggs)->toBe(4);
});

test('on n’écarte pas plus d’œufs qu’on n’en a ramassé', function () {
    expect(fn () => collecter($this->pondeuses, [
        'total_eggs_collected' => 10, 'broken_eggs' => 4, 'small_eggs' => 3, 'downgraded_eggs' => 4,
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('la rectification au bureau recalcule les incubables avec l’ajustement', function () {
    $collecte = collecter($this->pondeuses, ['total_eggs_collected' => 100]);

    $this->put(route('egg-productions.update', $collecte), [
        'total_eggs_collected' => 100, 'broken_eggs' => 2, 'small_eggs' => 1, 'downgraded_eggs' => 7,
    ])->assertSessionHasNoErrors();

    expect($collecte->fresh()->incubable_eggs)->toBe(90);
});

test('le terrain envoie son ajustement : mêmes incubables qu’au bureau', function () {
    Sanctum::actingAs($this->adminUser);

    $resultat = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'egg_collection.create',
        'payload' => [
            'uuid' => (string) Str::uuid(), 'batch_id' => $this->pondeuses->id,
            'production_date' => today()->toDateString(),
            'total_eggs_collected' => 80, 'broken_eggs' => 1, 'small_eggs' => 2, 'downgraded_eggs' => 3,
        ],
    ]]])->assertOk()->json('results.0');

    expect($resultat['status'])->toBe('success')
        ->and(EggProduction::withoutGlobalScopes()->first()->incubable_eggs)->toBe(74);
});

test('l’analyse du matin compte les anormaux comme des anomalies, et le calibre S au tri', function () {
    EggProduction::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->pondeuses->id,
        'production_date' => today()->subDay()->toDateString(),
        'total_eggs_collected' => 400, 'broken_eggs' => 4, 'small_eggs' => 16,   // 5 % casse + anomalies
    ]);

    $rapport = (new EggAnalysisService())->getDailyReport();
    $types = collect($rapport['irregularities'])->pluck('type');

    // `small_eggs` porte les anormaux (rectification, tri, stock « Anomalie ») :
    // 20 sur 400 font 5 % de casse + anomalies — et ce n'est PAS le calibre S.
    expect($types)->toContain('high_breakage')
        ->and($types)->not->toContain('small_eggs');
});
