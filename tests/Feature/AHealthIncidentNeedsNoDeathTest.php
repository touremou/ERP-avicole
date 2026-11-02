<?php

use App\Models\Batch;
use App\Models\HealthIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN INCIDENT SANITAIRE SANS MORT SE DÉCLARE — AU BUREAU COMME AU TERRAIN.
 *
 * Le bureau exigeait au moins un cadavre (`min:1`), le terrain acceptait zéro.
 * Or un incident n'entraîne pas toujours de mortalité immédiate : symptômes
 * respiratoires, chute d'appétit, coup de chaleur, blessures collectives,
 * traitement préventif.
 *
 * Décision de l'exploitation, règle commune (HealthIncident::reglesDeDeclaration) :
 * la mortalité est facultative (zéro par défaut) ; la gravité et l'ACTION PRISE
 * sont obligatoires, des deux côtés.
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

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
        'initial_quantity' => 100, 'current_quantity' => 100, 'arrival_date' => today()->subDays(20),
    ]);
});

function incidentAuBureau(array $champs): array
{
    return array_merge([
        'symptoms' => 'Râles respiratoires, chute d’appétit', 'severity' => 'modere',
        'action_taken' => 'Lot isolé, vitamines dans l’eau, vétérinaire appelé',
    ], $champs);
}

function incidentDuTerrain(Tests\TestCase $test, int $lot, array $champs): array
{
    Sanctum::actingAs(\App\Models\User::find(auth()->id()));

    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => 'health_incident.create',
        'payload' => array_merge([
            'uuid' => (string) Str::uuid(), 'batch_id' => $lot, 'incident_date' => today()->toDateString(),
            'symptoms' => 'Toux', 'severity' => 'mineur', 'action_taken' => 'Ventilation augmentée',
        ], $champs),
    ]]])->assertOk()->json('results.0');
}

test('le BUREAU déclare un incident sans mort', function () {
    $this->post(route('health.incidents.store'), incidentAuBureau(['batch_id' => $this->lot->id, 'mortality_count' => 0]))
        ->assertSessionHasNoErrors();

    $incident = HealthIncident::latest('id')->firstOrFail();
    expect((int) $incident->mortality_count)->toBe(0)
        ->and($incident->action_taken)->toBe('Lot isolé, vitamines dans l’eau, vétérinaire appelé');
});

test('la mortalité omise vaut zéro, des deux côtés', function () {
    $this->post(route('health.incidents.store'), incidentAuBureau(['batch_id' => $this->lot->id]))
        ->assertSessionHasNoErrors();

    $resultat = incidentDuTerrain($this, $this->lot->id, []);

    expect($resultat['status'])->toBe('success')
        ->and(HealthIncident::pluck('mortality_count')->map(fn ($m) => (int) $m)->all())->toBe([0, 0]);
});

test('l’ACTION PRISE est exigée, au bureau comme au terrain', function () {
    $this->post(route('health.incidents.store'), incidentAuBureau(['batch_id' => $this->lot->id, 'action_taken' => '']))
        ->assertSessionHasErrors('action_taken');

    expect(incidentDuTerrain($this, $this->lot->id, ['action_taken' => null])['status'])->toBe('validation_failed')
        ->and(HealthIncident::count())->toBe(0);
});

test('la GRAVITÉ est exigée, au bureau comme au terrain', function () {
    $sansGravite = incidentAuBureau(['batch_id' => $this->lot->id]);
    unset($sansGravite['severity']);
    $this->post(route('health.incidents.store'), $sansGravite)->assertSessionHasErrors('severity');

    expect(incidentDuTerrain($this, $this->lot->id, ['severity' => null])['status'])->toBe('validation_failed')
        ->and(HealthIncident::count())->toBe(0);
});

test('l’action prise du terrain est enregistrée', function () {
    expect(incidentDuTerrain($this, $this->lot->id, ['mortality_count' => 2])['status'])->toBe('success');

    $incident = HealthIncident::firstOrFail();
    expect($incident->action_taken)->toBe('Ventilation augmentée')
        ->and((int) $incident->mortality_count)->toBe(2);
});
