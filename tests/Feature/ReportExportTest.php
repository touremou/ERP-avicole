<?php

/**
 * Tests Feature — Rapports : export PDF & filtre multiespèces du flux de trésorerie.
 */

use App\Models\Batch;
use App\Models\Role;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\SpeciesSeeder;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    $admin = Role::firstOrCreate(
        ['name' => 'admin'],
        ['display_name' => 'Administrateur', 'label' => 'Administrateur', 'permissions' => ['L', 'C', 'M', 'S']]
    );
    $this->adminUser = User::factory()->create(['role_id' => $admin->id]);

    $this->seed(SpeciesSeeder::class);
});

test('le filtre flux de trésorerie propose et applique un filtre par espèce (pas seulement volaille)', function () {
    $poulet = Species::where('slug', 'poulet')->first();
    $chevre = Species::where('slug', 'chevre')->first();

    $batchPoulet = Batch::factory()->create([
        'code' => 'LOT-POULET',
        'type' => 'chair',
        'species_id' => $poulet->id,
        'arrival_date' => now()->subDays(10),
        'status' => 'Actif',
    ]);

    $batchChevre = Batch::factory()->create([
        'code' => 'LOT-CHEVRE',
        'type' => 'laitiere',
        'species_id' => $chevre->id,
        'arrival_date' => now()->subDays(10),
        'status' => 'Actif',
    ]);

    // Sans filtre : toutes les espèces sont proposées dans les filtres.
    $this->actingAs($this->adminUser)
        ->get(route('reports.monthly'))
        ->assertOk()
        ->assertSee('Espèce')
        ->assertSee($chevre->name_fr);

    // Filtre sur l'espèce "chèvre" : seul le lot chèvre apparaît.
    $this->actingAs($this->adminUser)
        ->get(route('reports.monthly', ['species' => $chevre->id]))
        ->assertOk()
        ->assertSee('LOT-CHEVRE')
        ->assertDontSee('LOT-POULET');
});

test('les rapports sont exportables en PDF avec les filtres courants', function () {
    $poulet = Species::where('slug', 'poulet')->first();

    Batch::factory()->create([
        'code' => 'LOT-PDF',
        'type' => 'chair',
        'species_id' => $poulet->id,
        'arrival_date' => now()->subDays(5),
        'status' => 'Actif',
    ]);

    $routes = [
        ['reports.monthly.pdf', []],
        ['reports.profit_loss.pdf', []],
        ['reports.nursery.pdf', []],
        ['reports.health_finance.pdf', []],
        ['reports.technical.pdf', []],
    ];

    foreach ($routes as [$name, $params]) {
        $response = $this->actingAs($this->adminUser)->get(route($name, $params));
        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('application/pdf');
    }
});
