<?php

use App\Models\CcpRecord;
use App\Models\TemperatureLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN RELEVÉ HACCP DATÉ DE DEMAIN, ACCEPTÉ PAR LE TERRAIN.
 *
 * Le registre sanitaire de l'abattoir a quatre entrées terrain. Le nettoyage
 * refusait une heure future, à la dérive d'horloge du téléphone près (quinze
 * minutes, `CLOCK_SKEW_TOLERANCE`). Le relevé CCP, la température et la
 * réception vif acceptaient N'IMPORTE QUELLE date. Le bureau, lui, horodate
 * toujours à l'instant (`now()`).
 *
 * Un téléphone réglé un jour en avance inscrivait donc au registre — celui
 * qu'on présente au contrôle vétérinaire — des températures relevées demain,
 * et le compteur « relevés du jour » les ignorait.
 *
 * La règle du nettoyage vaut désormais pour tout le registre.
 */

beforeEach(function () {
    $this->setUpRbac();
    Illuminate\Support\Facades\DB::table('farm_user')->updateOrInsert(
        ['farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id],
        ['is_default' => true, 'is_owner' => true, 'created_at' => now(), 'updated_at' => now()],
    );
    Sanctum::actingAs($this->adminUser);
});

function releveTerrain(Tests\TestCase $test, string $type, array $payload): array
{
    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => $type,
        'payload' => array_merge(['uuid' => (string) Str::uuid()], $payload),
    ]]])->assertOk()->json('results.0');
}

function temperatureA(\Carbon\CarbonInterface $quand): array
{
    return ['point' => array_key_first(TemperatureLog::POINTS), 'equipment_ref' => 'CF-01',
        'temperature' => 3.0, 'corrective_action' => 'RAS', 'releve_at' => $quand->toIso8601String()];
}

test('une température relevée DEMAIN est refusée', function () {
    expect(releveTerrain($this, 'temperature_log.create', temperatureA(now()->addDay()))['status'])
        ->toBe('validation_failed')
        ->and(TemperatureLog::withoutGlobalScopes()->count())->toBe(0);
});

test('un relevé CCP daté de demain est refusé', function () {
    $resultat = releveTerrain($this, 'ccp_record.create', [
        'ccp' => 'ccp3_refroidissement', 'mesures' => ['temperature_coeur' => 3.0],
        'corrective_action' => 'RAS', 'releve_at' => now()->addDay()->toIso8601String(),
    ]);

    expect($resultat['status'])->toBe('validation_failed')
        ->and(CcpRecord::withoutGlobalScopes()->count())->toBe(0);
});

test('la dérive d’horloge du téléphone reste tolérée, et un relevé d’hier passe', function () {
    foreach ([now()->addMinutes(5), now()->subDay()] as $quand) {
        expect(releveTerrain($this, 'temperature_log.create', temperatureA($quand))['status'])->toBe('success');
    }

    expect(TemperatureLog::withoutGlobalScopes()->count())->toBe(2);
});
