<?php

use App\Models\Batch;
use App\Models\Employee;
use App\Models\Module;
use App\Models\ModulePermission;
use App\Models\Role;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Support\ProfilsMetier;
use App\Support\SitesDuCompte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CHAQUE PROFIL NE VOIT QUE SON MÉTIER.
 *
 * Audit de l'exploitation : un technicien d'élevage ouvrait la liste des
 * clients et des fournisseurs, les budgets, le bilan financier sanitaire, et
 * n'avait pas les tâches. Trois règles, chacune déclarée une fois :
 *   • les TIERS (Annuaire) à qui traite avec eux ;
 *   • l'ARGENT à qui le gère (gate finances.voir : Dépenses ou Trésorerie) ;
 *   • les TÂCHES de l'équipe au Planning, chacun voyant les siennes.
 *
 * Chaque profil métier est connecté pour de vrai, et chaque écran appelé.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    ProfilsMetier::installer();
});

/** La ferme de setUpRbac (sa propriété est protégée, hors de portée des fonctions Pest). */
function fermeDeTest(): int
{
    return (int) \App\Models\Farm::where('code', 'FT-001')->value('id');
}

function compteDuProfil(Tests\TestCase $test, string $profil): User
{
    $compte = User::factory()->create(['role_id' => Role::where('name', $profil)->value('id')]);
    SitesDuCompte::rattacher($compte, [fermeDeTest()]);

    return $compte;
}

/** L'écran s'ouvre-t-il pour ce compte ? (un refus redirige avec une erreur) */
function ouvre(Tests\TestCase $test, User $compte, string $route, array $parametres = []): bool
{
    app('auth')->forgetGuards();
    $test->flushSession();
    session(['current_farm_id' => fermeDeTest()]);

    return $test->actingAs($compte)->get(route($route, $parametres))->getStatusCode() === 200;
}

const ECRANS_D_ARGENT = [
    'finance.index', 'expenses.index', 'budgets.index', 'purchases.index', 'treasury.index',
    'reports.health_finance', 'crop-reports.campaigns', 'crop-reports.inputs',
];

test('l’argent ne s’ouvre qu’à qui le gère', function () {
    foreach (array_keys(ProfilsMetier::PROFILS) as $profil) {
        $compte = compteDuProfil($this, $profil);
        $gereLArgent = Gate::forUser($compte)->allows('finances.voir');

        foreach (ECRANS_D_ARGENT as $ecran) {
            if (! $gereLArgent) {
                expect(ouvre($this, $compte, $ecran))->toBeFalse("{$profil} ouvre {$ecran}");
            }
        }

        $attendu = in_array($profil, ['profil-direction', 'profil-gerant', 'profil-comptable', 'profil-auditeur'], true);
        expect($gereLArgent)->toBe($attendu, "{$profil} : voir l'argent");
    }

    expect(ouvre($this, compteDuProfil($this, 'profil-comptable'), 'reports.health_finance'))->toBeTrue();
});

test('les clients et fournisseurs ne s’ouvrent qu’à qui traite avec eux', function () {
    $sansTiers = [
        'profil-technicien-elevage', 'profil-agent-terrain', 'profil-operateur-abattoir', 'profil-agent-provenderie',
        'profil-technicien-cultures', 'profil-magasinier', 'profil-veterinaire', 'profil-responsable-rh',
    ];
    foreach ($sansTiers as $profil) {
        $compte = compteDuProfil($this, $profil);
        expect(ouvre($this, $compte, 'clients.index'))->toBeFalse("{$profil} voit les clients")
            ->and(ouvre($this, $compte, 'providers.index'))->toBeFalse("{$profil} voit les fournisseurs");
    }

    // Le vendeur : ses clients (Commerce), pas les fournisseurs.
    $vendeur = compteDuProfil($this, 'profil-vendeur');
    expect(ouvre($this, $vendeur, 'clients.index'))->toBeTrue()
        ->and(ouvre($this, $vendeur, 'providers.index'))->toBeFalse();

    $gerant = compteDuProfil($this, 'profil-gerant');
    expect(ouvre($this, $gerant, 'clients.index'))->toBeTrue()
        ->and(ouvre($this, $gerant, 'providers.index'))->toBeTrue();
});

test('le technicien d’élevage répartit les tâches de son équipe, sans ouvrir la RH', function () {
    $technicien = compteDuProfil($this, 'profil-technicien-elevage');
    $agent = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif', 'department' => 'Elevage']);

    expect(ouvre($this, $technicien, 'tasks.index'))->toBeTrue()
        ->and(ouvre($this, $technicien, 'employees.index'))->toBeFalse()
        ->and(ouvre($this, $technicien, 'payroll.index'))->toBeFalse();

    $this->actingAs($technicien)->post(route('tasks.store'), [
        'title' => 'Vaccination Gumboro bâtiment A', 'category' => 'sante', 'employee_id' => $agent->id,
        'scheduled_date' => today()->toDateString(), 'priority' => 'haute',
    ])->assertSessionHasNoErrors()->assertSessionMissing('error');

    expect(TaskAssignment::where('employee_id', $agent->id)->where('title', 'Vaccination Gumboro bâtiment A')->exists())->toBeTrue();
});

test('chacun voit SES tâches, et le menu le mène à elles', function () {
    $agent = compteDuProfil($this, 'profil-agent-terrain');
    $fiche = Employee::factory()->create(['farm_id' => $this->farm->id, 'user_id' => $agent->id, 'status' => 'Actif']);
    $collegue = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);
    foreach ([[$fiche, 'Ramassage des œufs'], [$collegue, 'Tâche du collègue']] as [$employe, $titre]) {
        TaskAssignment::create([
            'employee_id' => $employe->id, 'title' => $titre, 'category' => 'controle',
            'scheduled_date' => today()->toDateString(), 'priority' => 'normale', 'status' => 'a_faire', 'proof_type' => 'aucune',
        ]);
    }

    $this->actingAs($agent)->get(route('tasks.index'))->assertOk()
        ->assertSee('Ramassage des œufs')->assertDontSee('Tâche du collègue')
        ->assertDontSee(route('tasks.store'));   // ni créer ni affecter

    $this->actingAs($agent)->get(route('dashboard'))->assertSee(route('tasks.index', ['mine' => 1]), false);
});

test('les montants des écrans opérationnels suivent la même règle', function () {
    expect(ouvre($this, compteDuProfil($this, 'profil-magasinier'), 'logistique.index'))->toBeTrue();

    $this->actingAs(compteDuProfil($this, 'profil-magasinier'))->get(route('logistique.index'))
        ->assertOk()->assertDontSee('Valeur du stock');
    $this->actingAs(compteDuProfil($this, 'profil-gerant'))->get(route('logistique.index'))
        ->assertOk()->assertSee('Valeur du stock');
});

test('clôturer un lot — son compte de résultat — est un acte de gestion', function () {
    $lot = Batch::factory()->create(['farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif']);

    expect(ouvre($this, compteDuProfil($this, 'profil-technicien-elevage'), 'batches.close_form', [$lot]))->toBeFalse()
        ->and(ouvre($this, compteDuProfil($this, 'profil-gerant'), 'batches.close_form', [$lot]))->toBeTrue();
});

test('la révision atteint les profils déjà installés, sans défaire le reste de leur matrice', function () {
    $technicien = Role::where('name', 'profil-technicien-elevage')->first();
    $cellule = fn (string $slug) => ModulePermission::where('role_id', $technicien->id)
        ->where('module_id', Module::where('slug', $slug)->value('id'));

    // L'installation d'hier : annuaire et dépenses ouverts, planning en lecture.
    foreach (['annuaire' => [true, false], 'depenses' => [true, true], 'planning' => [true, false]] as $slug => [$l, $c]) {
        ModulePermission::updateOrCreate(
            ['role_id' => $technicien->id, 'module_id' => Module::where('slug', $slug)->value('id')],
            ['can_read' => $l, 'can_create' => $c, 'can_modify' => false, 'can_delete' => false],
        );
    }
    // Un ajustement de l'administrateur, hors révision.
    ModulePermission::updateOrCreate(
        ['role_id' => $technicien->id, 'module_id' => Module::where('slug', 'cultures')->value('id')],
        ['can_read' => true],
    );

    (include database_path('migrations/2026_11_06_000001_business_profiles_least_privilege.php'))->up();

    expect($cellule('annuaire')->value('can_read'))->toBeFalse()
        ->and($cellule('depenses')->value('can_read'))->toBeFalse()
        ->and($cellule('planning')->value('can_create'))->toBeTrue()
        ->and($cellule('planning')->value('can_modify'))->toBeTrue()
        ->and($cellule('cultures')->value('can_read'))->toBeTrue();
});
