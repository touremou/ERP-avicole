<?php

use App\Models\Campaign;
use App\Models\Module;
use App\Models\Role;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    $manager = Role::firstOrCreate(
        ['name' => 'manager'],
        ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M']]
    );
    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $manager->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => false, 'updated_at' => $now, 'created_at' => $now]
        );
    }
    $this->managerUser = User::factory()->create(['role_id' => $manager->id]);

    Species::firstOrCreate(['slug' => 'mouton'], ['name_fr' => 'Mouton', 'family' => 'petit_ruminant', 'is_active' => true]);
});

test('une campagne ciblant une famille d\'espèce inexistante est refusée', function () {
    $this->actingAs($this->managerUser)
        ->post(route('campaigns.store'), [
            'name'          => 'Tabaski 2026',
            'type'          => 'tabaski',
            'target_family' => 'licorne', // famille inexistante
            'status'        => 'preparation',
            'target_date'   => now()->addMonths(2)->toDateString(),
        ])
        ->assertSessionHasErrors('target_family');

    expect(Campaign::where('name', 'Tabaski 2026')->exists())->toBeFalse();
});

test('une campagne ciblant une famille d\'espèce réelle est créée', function () {
    $this->actingAs($this->managerUser)
        ->post(route('campaigns.store'), [
            'name'          => 'Tabaski 2026',
            'type'          => 'tabaski',
            'target_family' => 'petit_ruminant',
            'status'        => 'preparation',
            'target_date'   => now()->addMonths(2)->toDateString(),
        ])
        ->assertSessionDoesntHaveErrors();

    expect(Campaign::where('name', 'Tabaski 2026')->where('target_family', 'petit_ruminant')->exists())->toBeTrue();
});
