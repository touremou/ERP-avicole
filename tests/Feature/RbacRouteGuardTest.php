<?php

use App\Console\Commands\AuditRbacCommand;
use App\Models\Employee;
use App\Models\TaskAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * GARDE-FOU RBAC — les deux erreurs que la convention `can:L|C|M|S` rend
 * silencieuses.
 *
 * Le middleware générique est résolu au module de la route par son PRÉFIXE DE
 * NOM. Deux façons de se tromper ne lèvent aucune erreur, ni au démarrage, ni à
 * l'exécution :
 *
 *  1. un préfixe absent de Module::routePrefixMap fait retomber le gate sur
 *     « accès si AU MOINS UN module accorde ce niveau » — la page s'ouvre pour
 *     n'importe quel profil, et personne ne le remarque ;
 *  2. une écriture gardée par `can:L` (niveau LECTURE) laisse un simple lecteur
 *     modifier.
 *
 * Ces deux tests échouent au lieu de laisser le trou passer en production. Ils
 * ont été écrits APRÈS avoir trouvé les deux cas réels (notifications en L,
 * crop-backfill hors carte) : leur rôle est d'empêcher le troisième.
 */

test('toute route à gate générique est rattachée à un module', function () {
    $unmapped = AuditRbacCommand::findings()['unmapped'];

    $message = "Préfixe(s) de route absent(s) de Module::routePrefixMap :\n";
    foreach ($unmapped as $prefix => $routes) {
        $message .= "  {$prefix} → " . implode(', ', array_slice($routes, 0, 5)) . "\n";
    }
    $message .= "Sans rattachement, can:L|C|M|S retombe sur « n'importe quel module accordant ce niveau ».";

    expect($unmapped)->toBe([], $message);
});

test('aucune écriture n’est gardée par le seul niveau LECTURE', function () {
    $weak = AuditRbacCommand::findings()['weak_writes'];

    expect($weak)->toBe([], "Écriture(s) gardée(s) par can:L :\n  " . implode("\n  ", $weak)
        . "\nUne écriture exige au moins C (création), M (modification) ou S (suppression).");
});

/*
 * PORTÉE PERSONNELLE — un agent doit atteindre SES données sans droit sur le
 * module qui les héberge. Les tâches vivent dans le module RH, mais un
 * technicien de cultures n'a aucun droit RH : lui refuser sa propre liste
 * revenait à lui refuser son travail. Le mobile l'autorisait déjà — c'est cette
 * divergence-là qui rendait le RBAC incohérent d'un support à l'autre.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Utilisateur SANS aucun droit RH, mais rattaché à une fiche employé. */
function technicianWithoutRh(): array
{
    $role = \App\Models\Role::create([
        'name' => 'tech-cultures-' . Str::random(4), 'label' => 'Technicien cultures',
        'display_name' => 'Technicien cultures', 'permissions' => ['L', 'C'],
    ]);
    // seedModuleMatrix est protégée : accessible via le binding Pest, mais la
    // ferme (propriété protégée) se lit par la session que setUpRbac renseigne.
    test()->seedModuleMatrix($role, ['L', 'C']);
    $farmId = session('current_farm_id');

    // On RETIRE tout droit RH : c'est la situation décrite (accès cultures et
    // planning seulement).
    DB::table('module_permissions')
        ->join('modules', 'modules.id', '=', 'module_permissions.module_id')
        ->whereIn('modules.slug', ['rh', 'admin'])
        ->where('module_permissions.role_id', $role->id)
        ->update(['can_read' => false, 'can_create' => false, 'can_modify' => false, 'can_delete' => false]);

    $user = \App\Models\User::factory()->create(['role_id' => $role->id]);
    \Illuminate\Support\Facades\Cache::forget("rbac_perms_{$user->id}");

    DB::table('farm_user')->insert([
        'farm_id' => $farmId, 'user_id' => $user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $employee = Employee::factory()->create(['status' => 'Actif', 'user_id' => $user->id]);

    return [$user, $employee];
}

test('un technicien sans droit RH voit SA liste de tâches', function () {
    [$user, $employee] = technicianWithoutRh();

    TaskAssignment::create([
        'employee_id' => $employee->id, 'title' => 'Traitement parcelle A',
        'category' => 'traitement', 'scheduled_date' => now()->toDateString(),
        'priority' => 'haute', 'status' => 'a_faire', 'proof_type' => 'aucune',
    ]);

    $this->actingAs($user)
        ->get(route('tasks.index'))
        ->assertOk()
        ->assertSee('Traitement parcelle A');
});

test('un technicien sans droit RH peut cocher SA tâche', function () {
    [$user, $employee] = technicianWithoutRh();

    $task = TaskAssignment::create([
        'employee_id' => $employee->id, 'title' => 'Sarclage',
        'category' => 'sarclage', 'scheduled_date' => now()->toDateString(),
        'priority' => 'normale', 'status' => 'a_faire', 'proof_type' => 'aucune',
    ]);

    $this->actingAs($user)
        ->post(route('tasks.complete', $task), ['notes' => 'Fait'])
        ->assertRedirect();

    expect($task->fresh()->status)->toBe('fait');
});

test('un technicien ne peut PAS cocher la tâche d’un collègue', function () {
    [$user] = technicianWithoutRh();
    $other = Employee::factory()->create(['status' => 'Actif']);

    $task = TaskAssignment::create([
        'employee_id' => $other->id, 'title' => 'Tâche du collègue',
        'category' => 'controle', 'scheduled_date' => now()->toDateString(),
        'priority' => 'normale', 'status' => 'a_faire', 'proof_type' => 'aucune',
    ]);

    $this->actingAs($user)->post(route('tasks.complete', $task), []);

    // La portée personnelle ne doit pas devenir une porte ouverte sur l'équipe.
    expect($task->fresh()->status)->toBe('a_faire');
});

test('un technicien sans droit RH ne voit pas les tâches de l’équipe', function () {
    [$user, $employee] = technicianWithoutRh();
    $other = Employee::factory()->create(['status' => 'Actif']);

    TaskAssignment::create([
        'employee_id' => $employee->id, 'title' => 'Ma tache a moi',
        'category' => 'controle', 'scheduled_date' => now()->toDateString(),
        'priority' => 'normale', 'status' => 'a_faire', 'proof_type' => 'aucune',
    ]);
    TaskAssignment::create([
        'employee_id' => $other->id, 'title' => 'Tache confidentielle du collegue',
        'category' => 'controle', 'scheduled_date' => now()->toDateString(),
        'priority' => 'normale', 'status' => 'a_faire', 'proof_type' => 'aucune',
    ]);

    $this->actingAs($user)
        ->get(route('tasks.index'))
        ->assertOk()
        ->assertSee('Ma tache a moi')
        ->assertDontSee('Tache confidentielle du collegue');
});

test('un compte sans fiche employé et sans droit RH reste refusé', function () {
    $role = \App\Models\Role::create([
        'name' => 'lecteur-' . Str::random(4), 'label' => 'Lecteur',
        'display_name' => 'Lecteur', 'permissions' => ['L'],
    ]);
    $this->seedModuleMatrix($role, ['L']);
    DB::table('module_permissions')
        ->join('modules', 'modules.id', '=', 'module_permissions.module_id')
        ->whereIn('modules.slug', ['rh', 'admin'])
        ->where('module_permissions.role_id', $role->id)
        ->update(['can_read' => false]);

    $user = \App\Models\User::factory()->create(['role_id' => $role->id]);
    \Illuminate\Support\Facades\Cache::forget("rbac_perms_{$user->id}");
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Ouvrir la porte au titulaire ne doit pas l'ouvrir à tout le monde.
    $this->actingAs($user)
        ->get(route('tasks.index'))
        ->assertRedirect(route('dashboard'));
});

/*
 * NOTIFICATIONS — une écriture ne se garde pas au niveau lecture.
 */

test('un lecteur ne peut PAS reconfigurer les notifications ni envoyer de test', function () {
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->readonlyUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Le rôle « viewer » n'a que L : il consulte les préférences…
    $this->actingAs($this->readonlyUser)
        ->get(route('notifications.preferences'))
        ->assertOk();

    // …mais ne les modifie pas, et ne consomme pas de crédit SMS/e-mail.
    foreach ([
        ['put', 'notifications.preferences.update'],
        ['post', 'notifications.test'],
        ['post', 'notifications.test_sms'],
        ['post', 'notifications.test_mail'],
    ] as [$verb, $name]) {
        $this->actingAs($this->readonlyUser)
            ->{$verb}(route($name), [])
            ->assertRedirect();
    }
});

test('un gestionnaire (M) reconfigure bien les notifications', function () {
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Non-régression : durcir le niveau ne doit pas fermer la porte à qui doit
    // pouvoir entrer.
    $this->actingAs($this->managerUser)
        ->get(route('notifications.preferences'))
        ->assertOk();
});
