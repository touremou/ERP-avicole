<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Formula;
use App\Models\HealthCheck;
use App\Models\MillMachine;
use App\Models\MillProduction;
use App\Models\ProductionType;
use App\Models\SlaughterOrder;
use App\Models\WaterReading;
use App\Models\WaterSource;
use App\Support\SitesDuCompte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CHAQUE LISTE NE PROPOSE QUE CE QUE LE SERVEUR ACCEPTE — AU BUREAU COMME AU TERRAIN.
 *
 * Signalé par l'exploitation : la traite du téléphone listait des lots de
 * volaille. L'audit qui a suivi a trouvé la même famille de défauts ailleurs :
 * un poulailler proposé pour des moutons, « Ponte 1 » au pointage d'un lot de
 * chair, un abattage proposé sur un lot en quarantaine, une machine en panne
 * au lancement d'un OP, une source d'eau désactivée au relevé, une couveuse en
 * panne à la mise à couver. Le serveur décrit désormais l'éligibilité, et la
 * refuse quand il l'acceptait.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    SitesDuCompte::rattacher($this->adminUser, [$this->farm->id]);
    Sanctum::actingAs($this->adminUser);
    $this->actingAs($this->adminUser);
});

function pousserUneOperation(Tests\TestCase $test, string $type, array $payload): array
{
    return $test->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => $type,
        'payload' => ['uuid' => (string) Str::uuid()] + $payload,
    ]]])->assertOk()->json('results.0');
}

test('le terrain sait quels bâtiments accueillent un type, et quelles phases d’aliment sont standard', function () {
    $pull = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.production_types.upserts');
    $chair = collect($pull)->firstWhere('slug', 'chair');
    expect($chair['building_types'])->toContain('mixte')->toContain('chair')->not->toContain('bergerie');

    $session = $this->getJson('/api/v1/auth/me')->json();
    expect(data_get($session, 'settings.feed_phases_standard') ?? data_get($session, 'user.settings.feed_phases_standard'))
        ->toContain('Chair Démarrage');
});

test('un ordre d’abattage sur un lot sous délai d’attente est marqué bloqué, et redescend chaque jour', function () {
    $lot = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif']);
    HealthCheck::create(['batch_id' => $lot->id, 'type' => 'Traitement', 'product_name' => 'Oxytétracycline',
        'intervention_date' => today()->toDateString(), 'withdrawal_days' => 7, 'mode_administration' => 'Eau de boisson']);
    $ordre = SlaughterOrder::create(['order_number' => 'AB-TEST-1', 'batch_id' => $lot->id,
        'planned_date' => today()->toDateString(), 'planned_quantity' => 10, 'status' => 'planifie',
        'requested_by' => $this->adminUser->id]);

    $ligne = collect($this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.slaughter_orders.upserts'))
        ->firstWhere('id', $ordre->id);
    expect($ligne['blocage'])->toContain('délai d’attente');

    $this->travel(8)->days();
    $ligne = collect($this->getJson('/api/v1/sync/pull?since='.urlencode(now()->subHour()->toIso8601String()))
        ->assertOk()->json('entities.slaughter_orders.upserts'))->firstWhere('id', $ordre->id);
    expect($ligne)->not->toBeNull()->and($ligne['blocage'])->toBeNull();
});

test('un OP ne se lance ni sur une machine en panne ni sur une formule désactivée', function () {
    $formule = Formula::factory()->create(['is_active' => true]);
    $inactive = Formula::factory()->create(['is_active' => false]);
    $panne = MillMachine::factory()->create(['status' => 'En Panne']);
    $ok = MillMachine::factory()->create(['status' => 'Opérationnel']);
    $chef = Employee::factory()->create(['status' => 'Actif']);

    $op = fn ($f, $m) => pousserUneOperation($this, 'mill_production.create',
        ['formula_id' => $f->id, 'machine_ids' => [$m->id], 'nb_bags' => 5, 'supervisor_id' => $chef->id]);

    expect($op($formule, $panne)['status'])->not->toBe('success')
        ->and($op($inactive, $ok)['status'])->not->toBe('success')
        ->and(MillProduction::count())->toBe(0)
        ->and($op($formule, $ok)['status'])->toBe('success');
});

test('une source d’eau désactivée ne reçoit plus de relevé', function () {
    $source = WaterSource::create(['name' => 'Ancien puits', 'type' => 'citerne', 'capacity_liters' => 5000,
        'current_level_liters' => 1000, 'status' => 'operationnel', 'is_active' => false]);

    $res = pousserUneOperation($this, 'water_reading.create', ['water_source_id' => $source->id,
        'reading_date' => today()->toDateString(), 'volume_consumed_liters' => 100]);

    expect($res['status'])->not->toBe('success')
        ->and(WaterReading::count())->toBe(0);
});

test('les cultures du catalogue descendent avec leur état d’activation', function () {
    $especes = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.crop_species.upserts');
    foreach ($especes as $espece) {
        expect($espece)->toHaveKey('is_active');
    }
});
