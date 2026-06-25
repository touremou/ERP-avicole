<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\HealthCheck;
use App\Models\Module;
use App\Models\Role;
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
    $this->building    = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);
    $this->batch       = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
        'arrival_date'     => now()->subMonths(2)->toDateString(),
    ]);
});

test('un produit vétérinaire périmé ne peut pas être administré', function () {
    $this->actingAs($this->managerUser)
        ->post(route('health.store'), [
            'batch_id'            => $this->batch->id,
            'intervention_date'   => now()->toDateString(),
            'type'                => 'Vaccin',
            'product_name'        => 'Gumboro',
            'mode_administration' => 'Eau de boisson',
            'expiry_date'         => now()->subDay()->toDateString(), // périmé hier
        ])
        ->assertSessionHasErrors('expiry_date');

    expect(HealthCheck::where('batch_id', $this->batch->id)->exists())->toBeFalse();
});

test('un produit valide (non périmé) est accepté', function () {
    $this->actingAs($this->managerUser)
        ->post(route('health.store'), [
            'batch_id'            => $this->batch->id,
            'intervention_date'   => now()->toDateString(),
            'type'                => 'Vaccin',
            'product_name'        => 'Gumboro',
            'mode_administration' => 'Eau de boisson',
            'expiry_date'         => now()->addMonths(6)->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    expect(HealthCheck::where('batch_id', $this->batch->id)->where('product_name', 'Gumboro')->exists())->toBeTrue();
});

test('un produit expirant le jour même de l\'intervention reste utilisable', function () {
    $this->actingAs($this->managerUser)
        ->post(route('health.store'), [
            'batch_id'            => $this->batch->id,
            'intervention_date'   => now()->toDateString(),
            'type'                => 'Vitamine',
            'product_name'        => 'Vitamine C',
            'mode_administration' => 'Eau de boisson',
            'expiry_date'         => now()->toDateString(), // expire aujourd'hui = OK
        ])
        ->assertSessionHasNoErrors();
});

test('un mode d\'administration hors des 4 anciens (ex. Spray, désinfection) est accepté', function () {
    // Régression : la colonne était un ENUM rigide (4 valeurs) → « Data truncated »
    // pour un mode proposé par le formulaire (Spray, Oculaire…). Désormais VARCHAR.
    foreach (['Spray', 'Oculaire'] as $mode) {
        $this->actingAs($this->managerUser)
            ->post(route('health.store'), [
                'batch_id'            => $this->batch->id,
                'intervention_date'   => now()->toDateString(),
                'type'                => 'Désinfection',
                'product_name'        => "Désinfection bassin ($mode)",
                'mode_administration' => $mode,
                'expiry_date'         => now()->addMonth()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        expect(HealthCheck::where('mode_administration', $mode)->exists())->toBeTrue();
    }
});
