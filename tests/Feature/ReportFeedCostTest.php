<?php

/**
 * Régression : le rapport mensuel valorise l'aliment CONSOMMÉ au coût figé à
 * la saisie (feed_unit_cost), source unique partagée avec la fiche lot, et non
 * plus au seul prix d'achat moyen — qui valorisait à 0 l'aliment produit en
 * interne (provenderie) faute d'enregistrement d'achat.
 */

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\ProductionType;
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

test('le rapport mensuel valorise l\'aliment produit en interne (sans achat) au coût figé', function () {
    $poulet = Species::where('slug', 'poulet')->first();

    $batch = Batch::factory()->create([
        'code'               => 'LOT-PROVENDERIE',
        'production_type_id' => ProductionType::resolveOrCreate('chair', $poulet->id)->id,
        'species_id'         => $poulet->id,
        'arrival_date'       => now()->subDays(10),
        'status'             => 'Actif',
        'current_quantity'   => 500,
    ]);

    // Aucun achat d'aliment (feedPurchases vide) : l'aliment provient de la
    // provenderie. La consommation porte le coût figé (437 GNF/kg).
    DailyCheck::factory()->create([
        'batch_id'       => $batch->id,
        'check_date'     => now()->subDays(2),
        'feed_consumed'  => 20,
        'feed_type'      => 'Chair Démarrage',
        'feed_unit_cost' => 437,
        'mortality'      => 0,
    ]);
    DailyCheck::factory()->create([
        'batch_id'       => $batch->id,
        'check_date'     => now()->subDay(),
        'feed_consumed'  => 20,
        'feed_type'      => 'Chair Démarrage',
        'feed_unit_cost' => 437,
        'mortality'      => 0,
    ]);

    $response = $this->actingAs($this->adminUser)
        ->get(route('reports.monthly'))
        ->assertOk();

    // Avant le correctif : avg_price_per_kg = 0 (achats inexistants) → le bloc
    // « Prix moyen/kg » ne s'affichait pas et l'aliment valait 0 GNF.
    $response->assertSee('Prix moyen/kg');
    $response->assertSee('437'); // CMP figé restitué
});
