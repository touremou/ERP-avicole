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

/**
 * Effectif du lot témoin : un nombre volontairement improbable comme MONTANT,
 * pour qu'un chiffre d'affaires ou une créance ne puisse pas le percuter par
 * hasard et faire échouer le cloisonnement pour une mauvaise raison.
 */
const EFFECTIF_TEMOIN = 7_531;

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
        'initial_quantity'   => EFFECTIF_TEMOIN,
        'current_quantity'   => EFFECTIF_TEMOIN,
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
        ->assertDontSee('Vue analytique consolidée')
        // Pas de carte « Densités Bâtiments » vide pour un profil non-élevage.
        ->assertDontSee('Densités Bâtiments');

    /*
     * LA DONNÉE D'EFFECTIF ELLE-MÊME NE TRANSITE PAS.
     *
     * Cette assertion cherchait « 1 234 » — un nombre à la française, séparé par
     * une ESPACE. Or l'effectif d'un lot s'affiche avec `number_format()` sans
     * arguments, donc « 1,234 », avec une VIRGULE. La garde ne pouvait donc pas
     * détecter la fuite qu'elle annonçait.
     *
     * En revanche elle tombait — au hasard — dès qu'un des montants en francs de
     * la page valait 1234 : le tableau de bord en formate treize à la française.
     * D'où un échec intermittent en intégration continue, sans rapport avec le
     * cloisonnement des rôles.
     *
     * On vérifie désormais les DEUX écritures, dérivées de la constante : le test
     * ne peut plus se désaligner du gabarit, et l'effectif témoin est choisi
     * assez improbable pour ne plus percuter un montant.
     */
    $response->assertDontSee(number_format(EFFECTIF_TEMOIN))
        ->assertDontSee(number_format(EFFECTIF_TEMOIN, 0, ',', ' '));
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
