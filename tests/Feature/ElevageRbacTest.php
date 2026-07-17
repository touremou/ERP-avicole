<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Module;
use App\Models\ProductionType;
use App\Models\Role;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RBAC Élevage : un « technicien » en lecture seule (elevage.L uniquement) peut
 * consulter les lots mais NI créer un suivi quotidien (C) NI supprimer un lot
 * (S) — le verrou de route (défense en profondeur) et les gates du contrôleur
 * refusent, et l'UI ne présente pas les boutons M/S.
 */

function elevageRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'elevage')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
         'can_modify' => in_array('M', $perms), 'can_delete' => in_array('S', $perms),
         'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);

    $this->technicien = User::factory()->create(['role_id' => elevageRole('technicien_ro', ['L'])->id]);

    $species = Species::firstOrCreate(
        ['slug' => 'poulet-chair-elevage-rbac'],
        ['name_fr' => 'Poulet de chair', 'family' => 'volaille', 'is_active' => true]
    );
    $type = ProductionType::resolveOrCreate('chair', $species->id);
    $this->batch = Batch::factory()->create([
        'farm_id'            => $this->farm->id,
        'building_id'        => Building::factory()->create(['type' => 'chair'])->id,
        'production_type_id' => $type->id,
        'status'             => 'Actif',
        'initial_quantity'   => 500,
        'current_quantity'   => 500,
    ]);
});

test('le technicien (L) ne peut PAS créer un suivi quotidien (route store = C)', function () {
    $before = DailyCheck::count();

    $response = $this->actingAs($this->technicien)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('daily-checks.store'), ['batch_id' => $this->batch->id, 'mortality' => 3]);

    // Verrou de route can:C : refus (403 direct ou 302 via gestionnaire d'exceptions)
    expect($response->status())->toBeIn([302, 403]);
    expect(DailyCheck::count())->toBe($before);
});

test('le technicien (L) ne peut PAS supprimer un lot (route destroy = S)', function () {
    $this->actingAs($this->technicien)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->delete(route('batches.destroy', $this->batch))
        ->assertRedirect();

    expect(Batch::whereKey($this->batch->id)->exists())->toBeTrue();
});

test("l'UI des lots ne présente pas le bouton Modifier au technicien (couche vue)", function () {
    $this->actingAs($this->technicien)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('batches.index'))
        ->assertOk()
        ->assertDontSee(route('batches.edit', $this->batch), false);
});

test('un manager (M/C) voit le bouton Modifier et peut ouvrir la création de suivi', function () {
    $this->actingAs($this->managerUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('batches.index'))
        ->assertOk()
        ->assertSee(route('batches.edit', $this->batch), false);

    // Le manager (C) franchit le verrou can:C du formulaire de création.
    $this->actingAs($this->managerUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('daily-checks.create', ['batch_id' => $this->batch->id]))
        ->assertOk();
});
