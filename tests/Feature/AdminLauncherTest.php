<?php

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Le module Administration n'a aucune fonction en lecture seule (toutes ses
 * routes exigent admin.S). Sa tuile ne doit donc PAS apparaître dans le
 * lanceur pour un rôle qui n'a pas admin.S — même s'il a un admin.L résiduel.
 */

test("un rôle avec admin.L (mais pas admin.S) ne voit PAS la tuile Administration", function () {
    $this->setUpRbac();

    $role = Role::firstOrCreate(['name' => 'faux_admin'], ['label' => 'Faux', 'display_name' => 'Faux', 'permissions' => ['L']]);
    $adminModuleId = Module::where('slug', 'admin')->value('id');
    // Lecture admin accordée par erreur, MAIS pas la suppression (admin.S).
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $adminModuleId],
        ['can_read' => true, 'can_create' => false, 'can_modify' => false, 'can_delete' => false,
         'created_at' => now(), 'updated_at' => now()]
    );
    $user = User::factory()->create(['role_id' => $role->id]);

    $slugs = $user->getAccessibleModules()->pluck('slug');
    expect($slugs->contains('admin'))->toBeFalse();
});

test("l'admin (admin.S) voit bien la tuile Administration", function () {
    $this->setUpRbac();

    $slugs = $this->adminUser->getAccessibleModules()->pluck('slug');
    expect($slugs->contains('admin'))->toBeTrue();
});
