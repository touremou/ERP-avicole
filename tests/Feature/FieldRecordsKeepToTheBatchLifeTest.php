<?php

use App\Actions\EggProduction\RecordEggCollection;
use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\EggProduction;
use App\Models\HealthCheck;
use App\Models\ProductionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN LOT CLÔTURÉ, UNE DATE FUTURE, UNE DATE D'AVANT L'ARRIVÉE : LE BUREAU
 * REFUSAIT, LE TERRAIN ENREGISTRAIT.
 *
 * Le pointage journalier et la collecte d'œufs ont chacun une action commune
 * (`RecordDailyCheck`, `RecordEggCollection`), appelée par le bureau ET par la
 * synchro. Mais les règles de VIE DU LOT vivaient dans les seules requêtes web :
 *
 *   • lot ACTIF          — « Ce lot est clôturé. Saisie impossible. » ;
 *   • date PAS FUTURE    — `before_or_equal:today` ;
 *   • date APRÈS L'ARRIVÉE — `AfterBatchArrival`.
 *
 * Mesuré, par la synchro :
 *
 *   • un pointage de 5 morts sur un lot CLÔTURÉ → accepté. L'effectif du lot
 *     fermé baisse APRÈS son bilan de clôture : mortalité, IC et coût par sujet
 *     du rapport ne correspondent plus au lot ;
 *   • un pointage daté de DEMAIN → accepté. Le pointage du vrai lendemain est
 *     ensuite refusé en « conflit » : il en existe déjà un à cette date ;
 *   • un pointage d'AVANT L'ARRIVÉE → accepté, à un âge négatif ;
 *   • une collecte sur un lot clôturé → accepté ;
 *   • une intervention sanitaire d'avant l'arrivée → accepté.
 *
 * Un pointage saisi hors-ligne puis rejoué après la clôture du lot n'a rien
 * d'exotique : c'est le scénario même de la file d'attente du téléphone.
 *
 * ─── LA PONTE AVAIT DEUX DÉFINITIONS ───
 *
 * « Ce lot pond-il ? » se lisait sur le TYPE DE PRODUCTION à l'écran
 * (`Batch::tracksEggs`) et sur une liste de types écrite à la main dans la
 * requête (`ponte`, `repro`, `reproducteur`). Un bélier reproducteur passait la
 * seconde. Le terrain, lui, n'en appliquait aucune. La règle vit désormais dans
 * l'action, sur `tracksEggs()`, pour toutes les portes.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    // Contexte de ferme de l'API (cf. SetApiFarmContext) : sans rattachement,
    // `farmScopedExists('batches')` refuse le lot pour une autre raison.
    \Illuminate\Support\Facades\DB::table('farm_user')->updateOrInsert(
        ['farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id],
        ['is_default' => true, 'is_owner' => true, 'created_at' => now(), 'updated_at' => now()],
    );
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'arrival_date'     => today()->subDays(20),
        'initial_quantity' => 100,
        'current_quantity' => 100,
    ]);

    $this->pondeuse = Batch::factory()->create([
        'farm_id'            => $this->farm->id,
        'building_id'        => $this->building->id,
        'status'             => 'Actif',
        'production_type_id' => ProductionType::resolveOrCreate('ponte', null)->id,
        'arrival_date'       => today()->subDays(200),
        'initial_quantity'   => 100,
        'current_quantity'   => 100,
    ]);
});

/** Pousse UNE opération par l'API de synchro, comme le téléphone le fait. */
function pousserSurLeLot(object $test, \App\Models\User $agent, string $type, array $payload): array
{
    Sanctum::actingAs($agent);

    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => $type,
        'payload' => array_merge(['uuid' => (string) Str::uuid()], $payload),
    ]]])->assertOk()->json('results.0');
}

/** Un refus définitif : la synchro le sort de la file vers « À corriger ». */
function estRefuse(array $resultat): bool
{
    return in_array($resultat['status'], ['conflict', 'validation_failed'], true);
}

// ── POINTAGE JOURNALIER ──────────────────────────────────────────────────

test('le TERRAIN ne pointe plus un lot clôturé', function () {
    $this->lot->update(['status' => 'Terminé']);

    $resultat = pousserSurLeLot($this, $this->adminUser, 'daily_check.create', [
        'batch_id' => $this->lot->id, 'check_date' => today()->toDateString(), 'mortality' => 5,
    ]);

    expect(estRefuse($resultat))->toBeTrue()
        ->and(DailyCheck::withoutGlobalScopes()->count())->toBe(0)
        ->and((int) $this->lot->fresh()->current_quantity)->toBe(100);
});

test('le TERRAIN ne pointe plus demain', function () {
    $resultat = pousserSurLeLot($this, $this->adminUser, 'daily_check.create', [
        'batch_id' => $this->lot->id, 'check_date' => today()->addDay()->toDateString(), 'mortality' => 1,
    ]);

    expect(estRefuse($resultat))->toBeTrue()
        ->and(DailyCheck::withoutGlobalScopes()->count())->toBe(0);
});

test('le TERRAIN ne pointe plus avant l’arrivée du lot', function () {
    $resultat = pousserSurLeLot($this, $this->adminUser, 'daily_check.create', [
        'batch_id' => $this->lot->id, 'check_date' => today()->subDays(30)->toDateString(), 'mortality' => 1,
    ]);

    expect(estRefuse($resultat))->toBeTrue()
        ->and(DailyCheck::withoutGlobalScopes()->count())->toBe(0);
});

test('le pointage du jour d’arrivée et d’aujourd’hui passent — les bornes', function () {
    foreach ([today()->subDays(20), today()] as $jour) {
        $resultat = pousserSurLeLot($this, $this->adminUser, 'daily_check.create', [
            'batch_id' => $this->lot->id, 'check_date' => $jour->toDateString(), 'mortality' => 1,
        ]);

        expect($resultat['status'])->toBe('success');
    }

    expect((int) $this->lot->fresh()->current_quantity)->toBe(98);
});

test('le BUREAU garde le même refus sur un lot clôturé — non-régression', function () {
    $this->lot->update(['status' => 'Terminé']);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('daily-checks.store'), [
            'batch_id' => $this->lot->id, 'check_date' => today()->toDateString(),
            'mortality' => 5, 'feed_consumed' => 0, 'feed_type' => '', 'health_status' => 'Normal',
        ])
        ->assertSessionHasErrors('batch_id');

    expect(DailyCheck::withoutGlobalScopes()->count())->toBe(0);
});

// ── COLLECTE D'ŒUFS ──────────────────────────────────────────────────────

test('le TERRAIN ne collecte plus sur un lot clôturé', function () {
    $this->pondeuse->update(['status' => 'Terminé']);

    $resultat = pousserSurLeLot($this, $this->adminUser, 'egg_collection.create', [
        'batch_id' => $this->pondeuse->id, 'production_date' => today()->toDateString(),
        'total_eggs_collected' => 80,
    ]);

    expect(estRefuse($resultat))->toBeTrue()
        ->and(EggProduction::withoutGlobalScopes()->count())->toBe(0);
});

test('le TERRAIN ne collecte plus sur un lot qui ne pond pas', function () {
    $resultat = pousserSurLeLot($this, $this->adminUser, 'egg_collection.create', [
        'batch_id' => $this->lot->id, 'production_date' => today()->toDateString(),
        'total_eggs_collected' => 10,
    ]);

    expect(estRefuse($resultat))->toBeTrue()
        ->and(EggProduction::withoutGlobalScopes()->count())->toBe(0);
});

test('le TERRAIN ne déclare plus plus de cassés et de petits que d’œufs', function () {
    $resultat = pousserSurLeLot($this, $this->adminUser, 'egg_collection.create', [
        'batch_id' => $this->pondeuse->id, 'production_date' => today()->toDateString(),
        'total_eggs_collected' => 10, 'broken_eggs' => 8, 'small_eggs' => 5,
    ]);

    expect(estRefuse($resultat))->toBeTrue()
        ->and(EggProduction::withoutGlobalScopes()->count())->toBe(0);
});

test('« pond-il ? » est UNE règle : le type de production, pas une liste de types', function () {
    /*
     * Un lot dont le type historique est « reproducteur » mais dont le type de
     * production ne suit pas la ponte (bélier, bouc) : l'écran refusait déjà la
     * collecte, la requête l'acceptait.
     */
    $sansPonte = ProductionType::resolveOrCreate('chair', null);
    $belier = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'type' => 'reproducteur', 'production_type_id' => $sansPonte->id,
        'arrival_date' => today()->subDays(200), 'initial_quantity' => 50, 'current_quantity' => 50,
    ]);

    expect($belier->fresh()->tracksEggs())->toBeFalse();

    expect(fn () => app(RecordEggCollection::class)->execute([
        'batch_id' => $belier->id, 'production_date' => today()->toDateString(),
        'total_eggs_collected' => 10,
    ]))->toThrow(ValidationException::class);
});

test('une collecte normale passe toujours par le terrain — non-régression', function () {
    $resultat = pousserSurLeLot($this, $this->adminUser, 'egg_collection.create', [
        'batch_id' => $this->pondeuse->id, 'production_date' => today()->toDateString(),
        'total_eggs_collected' => 80, 'broken_eggs' => 2, 'small_eggs' => 3,
    ]);

    expect($resultat['status'])->toBe('success')
        ->and((int) EggProduction::withoutGlobalScopes()->value('total_eggs_collected'))->toBe(80);
});

// ── INTERVENTION SANITAIRE ───────────────────────────────────────────────

test('le TERRAIN ne traite plus un lot avant son arrivée', function () {
    $resultat = pousserSurLeLot($this, $this->adminUser, 'health_check.create', [
        'batch_id' => $this->lot->id, 'intervention_date' => today()->subDays(30)->toDateString(),
        'type' => 'Vaccin', 'product_name' => 'Newcastle HB1', 'mode_administration' => 'Eau de boisson',
    ]);

    expect(estRefuse($resultat))->toBeTrue()
        ->and(HealthCheck::withoutGlobalScopes()->count())->toBe(0);
});

// ── INCIDENT SANITAIRE ───────────────────────────────────────────────────

test('un incident ne se déclare plus sur un lot soldé ou clôturé — au terrain comme au bureau', function () {
    $this->lot->update(['current_quantity' => 0]);   // le dernier animal est sorti
    expect($this->lot->fresh()->status)->toBe('Soldé');

    $resultat = pousserSurLeLot($this, $this->adminUser, 'health_incident.create', [
        'batch_id' => $this->lot->id, 'incident_date' => today()->toDateString(),
        'symptoms' => 'Toux', 'severity' => \App\Models\HealthIncident::SEVERITY_MINOR, 'action_taken' => 'Observation',
    ]);
    expect(estRefuse($resultat))->toBeTrue();

    $this->actingAs($this->adminUser)->post(route('health.incidents.store'), [
        'batch_id' => $this->lot->id, 'symptoms' => 'Toux', 'severity' => \App\Models\HealthIncident::SEVERITY_MINOR, 'action_taken' => 'Observation',
    ])->assertSessionHasErrors('batch_id');

    expect(\App\Models\HealthIncident::withoutGlobalScopes()->count())->toBe(0);
});

test('le TERRAIN ne date plus un incident d’avant l’arrivée du lot', function () {
    $resultat = pousserSurLeLot($this, $this->adminUser, 'health_incident.create', [
        'batch_id' => $this->lot->id, 'incident_date' => today()->subDays(40)->toDateString(),
        'symptoms' => 'Toux', 'severity' => \App\Models\HealthIncident::SEVERITY_MINOR, 'action_taken' => 'Observation',
    ]);

    expect(estRefuse($resultat))->toBeTrue();
});
