<?php

use App\Models\Batch;
use App\Models\Farm;
use App\Models\Incubation;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);
});

/**
 * Crée un rôle dont les permissions (L/C) ne sont accordées que sur le
 * module passé en paramètre, tous les autres modules restant à zéro.
 * Permet de vérifier que ChickDispatchController contrôle bien le module
 * `production` (et non `elevage`) conformément à Module::routePrefixMap().
 */
function makeSingleModuleRole(string $name, string $moduleSlug): Role
{
    $role = Role::firstOrCreate(
        ['name' => $name],
        ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => []]
    );

    $now = now();
    foreach (Module::all() as $module) {
        $granted = $module->slug === $moduleSlug;
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $module->id],
            [
                'can_read'   => $granted,
                'can_create' => $granted,
                'can_modify' => false,
                'can_delete' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    return $role;
}

function makeIncubation(): Incubation
{
    $batch = Batch::factory()->create();

    return Incubation::create([
        'batch_id'            => $batch->id,
        'code_incubation'     => 'INC-' . uniqid(),
        'start_date'          => now()->subDays(5),
        'hatch_date_expected' => now()->addDays(16),
        'eggs_count'          => 1000,
        'fertile_eggs'        => 900,
        'hatched_chicks'      => 800,
        'status'              => 'mirage_fait',
    ]);
}

test('un utilisateur avec la permission L sur le module production peut consulter le dispatch d\'une incubation', function () {
    // Garde-fou (cf. Module::routePrefixMap) : la route chick-dispatches.show
    // appartient au module `production`. ChickDispatchController::show()
    // doit donc contrôler `production.L`, pas `elevage.L`.
    $role = makeSingleModuleRole('production_only', 'production');
    $user = User::factory()->create(['role_id' => $role->id]);

    $incubation = makeIncubation();

    $this->actingAs($user)
        ->get(route('chick-dispatches.show', $incubation->id))
        ->assertOk();
});

test('un utilisateur avec seulement la permission L sur le module élevage ne peut pas consulter le dispatch d\'une incubation', function () {
    $role = makeSingleModuleRole('elevage_only', 'elevage');
    $user = User::factory()->create(['role_id' => $role->id]);

    $incubation = makeIncubation();

    $this->actingAs($user)
        ->get(route('chick-dispatches.show', $incubation->id))
        ->assertRedirect()
        ->assertSessionHas('error');
});
