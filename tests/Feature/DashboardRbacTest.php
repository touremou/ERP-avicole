<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Module;
use App\Models\ProductionType;
use App\Models\Role;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Cloisonnement RBAC du tableau de bord : un vendeur (commerce.L+C uniquement)
 * ne doit voir NI les effectifs/mortalité (élevage), ni les silos (logistique),
 * ni la production végétale — ni côté données (contrôleur), ni côté widgets.
 * L'admin, lui, voit tout. La vue analytique (séries d'élevage) lui est fermée.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Rôle « vendeur » : L+C sur commerce UNIQUEMENT (matrice réelle).
    $vendeur = Role::firstOrCreate(
        ['name' => 'vendeur'],
        ['label' => 'Vendeur', 'display_name' => 'Vendeur', 'permissions' => ['L', 'C']]
    );
    $commerceModuleId = Module::where('slug', 'commerce')->value('id');
    if ($commerceModuleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $vendeur->id, 'module_id' => $commerceModuleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => false, 'can_delete' => false,
             'created_at' => now(), 'updated_at' => now()]
        );
    }
    // Le module dashboard lui-même reste lisible (page d'accueil).
    $dashModuleId = Module::where('slug', 'dashboard')->value('id');
    if ($dashModuleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $vendeur->id, 'module_id' => $dashModuleId],
            ['can_read' => true, 'can_create' => false, 'can_modify' => false, 'can_delete' => false,
             'created_at' => now(), 'updated_at' => now()]
        );
    }
    $this->vendeur = User::factory()->create(['role_id' => $vendeur->id]);

    // Données d'élevage bien réelles : si elles fuyaient, le test le verrait.
    session(['current_farm_id' => $this->farm->id]);
    $species = Species::firstOrCreate(
        ['slug' => 'poulet-chair-rbac'],
        ['name_fr' => 'Poulet de chair', 'family' => 'volaille', 'is_active' => true]
    );
    $type = ProductionType::resolveOrCreate('chair', $species->id);
    Batch::factory()->create([
        'farm_id'            => $this->farm->id,
        'building_id'        => Building::factory()->create(['type' => 'chair'])->id,
        'production_type_id' => $type->id,
        'status'             => 'Actif',
        'initial_quantity'   => 1234,
        'current_quantity'   => 1234,
    ]);
});

test('un vendeur (commerce.L) ne voit pas les widgets élevage/stocks du dashboard', function () {
    $response = $this->actingAs($this->vendeur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('dashboard'))
        ->assertOk();

    // Widgets d'élevage absents (libellés des blocs cloisonnés).
    $response->assertDontSee('Effectif Actif')
        ->assertDontSee('Mortalité Période')
        ->assertDontSee('Bandes Actives')
        ->assertDontSee('Vue analytique consolidée');

    // La donnée d'effectif elle-même ne transite pas (1 234 sujets).
    $response->assertDontSee('1 234');
});

test("l'admin voit les widgets élevage du dashboard", function () {
    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Effectif Actif')
        ->assertSee('Bandes Actives');
});

test('la vue analytique (séries élevage) est refusée à un vendeur', function () {
    // Le gestionnaire d'exceptions de l'app convertit le refus en redirection
    // (flash d'erreur) : l'important est que la page de données ne soit PAS
    // servie (pas de 200).
    $response = $this->actingAs($this->vendeur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('dashboard.analytics'));

    expect($response->status())->toBeIn([302, 403]);

    // Et l'admin y accède normalement.
    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('dashboard.analytics'))
        ->assertOk();
});
