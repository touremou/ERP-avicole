<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Provider;
use App\Models\Role;
use App\Models\SlaughterOrder;
use App\Models\SlaughterReception;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Couche WEB du cœur sanitaire HACCP : rendu des écrans (attrape les
 * variables manquantes), blocage/libération avec la bonne hiérarchie de
 * gates (M bloque, SEUL S libère), RG-04 (réception refusée → pas d'ordre),
 * immuabilité (aucune route update/delete sur les registres).
 */

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FA-001'], ['name' => 'Ferme A', 'is_active' => true]);

    $makeRole = function (string $name, array $perms) {
        $role = Role::firstOrCreate(
            ['name' => $name],
            ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]
        );
        $now = now();
        foreach (Module::pluck('id') as $moduleId) {
            DB::table('module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                [
                    'can_read'   => in_array('L', $perms, true),
                    'can_create' => in_array('C', $perms, true),
                    'can_modify' => in_array('M', $perms, true),
                    'can_delete' => in_array('S', $perms, true),
                    'updated_at' => $now, 'created_at' => $now,
                ]
            );
        }

        return $role;
    };

    // « manager » = chef d'équipe (L,C,M) ; « qualite » = niveau S complet.
    $this->manager = User::factory()->create(['role_id' => $makeRole('manager', ['L', 'C', 'M'])->id]);
    $this->qualite = User::factory()->create(['role_id' => $makeRole('qualite', ['L', 'C', 'M', 'S'])->id]);

    foreach ([$this->manager, $this->qualite] as $user) {
        DB::table('farm_user')->insert([
            'farm_id' => $this->farm->id, 'user_id' => $user->id,
            'is_default' => true, 'is_owner' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    session(['current_farm_id' => $this->farm->id]);
});

function webOrder(User $requester): SlaughterOrder
{
    $batch = Batch::factory()->create([
        'building_id'      => Building::factory()->create(['type' => 'chair'])->id,
        'status'           => 'Actif',
        'initial_quantity' => 100,
        'current_quantity' => 100,
    ]);

    return SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 60,
        'status' => 'planifie', 'requested_by' => $requester->id,
    ]);
}

test('les écrans HACCP se rendent (réceptions, registres, dashboard, création d\'ordre)', function () {
    Provider::factory()->create();
    webOrder($this->manager);
    $this->actingAs($this->manager);

    foreach ([
        route('slaughter.receptions.index'),
        route('slaughter.receptions.create'),
        route('slaughter.registres.ccp'),
        route('slaughter.registres.ccp.create'),
        route('slaughter.registres.temperatures'),
        route('slaughter.registres.nettoyage'),
        route('slaughter.dashboard'),
        route('slaughter.orders.create'),
    ] as $url) {
        $this->get($url)->assertOk();
    }
});

test('blocage par M, libération REFUSÉE à M et accordée à S, motifs obligatoires', function () {
    $order = webOrder($this->manager);

    // Blocage sans motif → erreur de validation.
    $this->actingAs($this->manager)
        ->patch(route('slaughter.orders.block', $order), [])
        ->assertSessionHasErrors('reason');

    // Blocage avec motif (abattoir.M).
    $this->actingAs($this->manager)
        ->patch(route('slaughter.orders.block', $order), ['reason' => 'Suspicion sanitaire'])
        ->assertRedirect();
    expect($order->fresh()->status)->toBe('bloque');

    // Le manager (M sans S) ne peut PAS libérer : refus (redirection avec
    // erreur, convention du contrôleur) et l'ordre RESTE bloqué.
    $this->actingAs($this->manager)
        ->patch(route('slaughter.orders.release', $order), ['reason' => 'tentative'])
        ->assertRedirect()
        ->assertSessionHas('error');
    expect($order->fresh()->status)->toBe('bloque');

    // Le niveau qualité (S) libère, motif tracé.
    $this->actingAs($this->qualite)
        ->patch(route('slaughter.orders.release', $order), ['reason' => 'Analyse conforme, avis vétérinaire favorable'])
        ->assertRedirect();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe('planifie')
        ->and($fresh->release_reason)->toContain('Analyse conforme')
        ->and($fresh->released_by_id)->toBe($this->qualite->id);
});

test("RG-04 : une réception refusée ne peut pas donner d'ordre d'abattage", function () {
    $provider = Provider::factory()->create();
    $refused = SlaughterReception::create([
        'provider_id' => $provider->id, 'reception_date' => now()->toDateString(),
        'received_quantity' => 50, 'total_live_weight_kg' => 90,
        'sanitary_state' => 'non_conforme', 'fasting_respected' => 'non',
        'decision' => 'refuse', 'decision_reason' => 'Sujets malades',
        'controller_id' => $this->manager->id, 'validated_at' => now(),
    ]);

    $this->actingAs($this->manager)
        ->post(route('slaughter.orders.store'), [
            'reception_id'     => $refused->id,
            'planned_date'     => now()->toDateString(),
            'planned_quantity' => 50,
        ])
        ->assertSessionHasErrors();

    expect(SlaughterOrder::where('reception_id', $refused->id)->exists())->toBeFalse();
});

test('les registres sont insert-only : aucune route update/delete n\'existe', function () {
    $names = collect(app('router')->getRoutes()->getRoutesByName())->keys();

    foreach (['receptions', 'registres.ccp', 'registres.temperatures', 'registres.nettoyage'] as $prefix) {
        expect($names->first(fn ($n) => str_starts_with($n, "slaughter.{$prefix}")
            && (str_contains($n, 'update') || str_contains($n, 'destroy') || str_contains($n, 'edit'))))
            ->toBeNull();
    }
});

test('l\'export PDF du registre des températures répond', function () {
    $this->actingAs($this->qualite);

    app(\App\Actions\Slaughter\RecordTemperatureLog::class)->execute([
        'point' => 'chambre_froide_positive', 'temperature' => 3.2,
        'operator_id' => $this->qualite->id, 'releve_at' => now(),
    ]);

    $this->get(route('slaughter.registres.export', [
        'type' => 'temperatures',
        'from' => now()->subDay()->toDateString(),
        'to'   => now()->toDateString(),
    ]))->assertOk()->assertHeader('content-type', 'application/pdf');
});
