<?php

use App\Models\Module;
use App\Models\ProductionType;
use App\Models\Protocol;
use App\Models\Role;
use App\Models\Species;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

    // Un type de production valide pour l'espèce poulet (référence import).
    $poulet = Species::firstOrCreate(['slug' => 'poulet'], ['name_fr' => 'Poulet', 'family' => 'volaille', 'is_active' => true]);
    ProductionType::firstOrCreate(['species_id' => $poulet->id, 'slug' => 'chair'], ['name_fr' => 'Chair', 'is_active' => true]);
});

function importFile(array $payload): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'proto') . '.json';
    file_put_contents($path, json_encode($payload));

    return new UploadedFile($path, 'protocols.json', 'application/json', null, true);
}

test('un protocole importé avec un type de production valide est créé', function () {
    $file = importFile([
        ['name' => 'Proto Chair Importé', 'type' => 'chair', 'steps' => []],
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('protocols.import'), ['protocol_file' => $file])
        ->assertRedirect(route('protocols.index'));

    expect(Protocol::where('name', 'Proto Chair Importé')->where('type', 'chair')->exists())->toBeTrue();
});

test('un protocole importé avec un type inconnu est ignoré (jamais applicable)', function () {
    $file = importFile([
        ['name' => 'Proto Fantaisiste', 'type' => 'type_inexistant', 'steps' => []],
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('protocols.import'), ['protocol_file' => $file])
        ->assertRedirect(route('protocols.index'));

    expect(Protocol::where('name', 'Proto Fantaisiste')->exists())->toBeFalse();
});
