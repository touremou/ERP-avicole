<?php

use App\Models\Employee;
use App\Models\Farm;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LIEN COMPTE ↔ FICHE EMPLOYÉ — indépendant du site sélectionné.
 *
 * Symptôme rapporté : le web affiche la fiche, le mobile répond « votre compte
 * n'est pas rattaché à une fiche employé », et l'agent n'accède pas à ses
 * tâches. Même compte, même base, deux réponses.
 *
 * Cause : User::employee() traversait le scope de ferme. Or « suis-je un
 * employé ? » est une propriété de la PERSONNE, pas du site actuellement
 * résolu — et le web et le mobile ne résolvent pas forcément le même (le mobile
 * passe par SetApiFarmContext et un en-tête X-Farm-Id).
 */

beforeEach(function () {
    $this->setUpRbac();
});

/**
 * Un compte dont la fiche employé est rattachée à un AUTRE site que celui
 * résolu. Les propriétés protégées du cas de test ne sont pas lisibles depuis
 * une fonction de module (Pest) : la ferme et le rôle sont donc passés.
 */
function accountWithEmployeeOnOtherFarm(int $currentFarmId, int $roleId): array
{
    $otherFarm = Farm::create([
        'name' => 'Kérouané ' . uniqid(), 'code' => 'F-K-' . uniqid(), 'is_active' => true,
    ]);

    $user = User::factory()->create(['role_id' => $roleId]);

    DB::table('farm_user')->insert([
        ['farm_id' => $currentFarmId, 'user_id' => $user->id, 'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now()],
        ['farm_id' => $otherFarm->id, 'user_id' => $user->id, 'is_default' => false, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $employee = Employee::factory()->create([
        'farm_id' => $otherFarm->id, 'user_id' => $user->id,
        'status' => 'Actif', 'contract_type' => 'CDI',
    ]);

    return [$user, $employee, $otherFarm];
}

test('la fiche est trouvée même quand la ferme résolue n’est pas la sienne', function () {
    [$user, $employee] = accountWithEmployeeOnOtherFarm($this->farm->id, $this->readonlyUser->role_id);

    // Le site courant est Kindia, la fiche est à Kérouané : c'est exactement la
    // situation où le mobile répondait « aucune fiche ».
    session(['current_farm_id' => $this->farm->id]);

    expect($user->fresh()->employee?->id)->toBe($employee->id);
});

test('/auth/me renvoie l’employee_id quelle que soit la ferme résolue', function () {
    [$user, $employee] = accountWithEmployeeOnOtherFarm($this->farm->id, $this->readonlyUser->role_id);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();

    expect($response->json('scope.employee_id'))->toBe($employee->id);
});

test('l’agent accède à SES tâches depuis le mobile', function () {
    // La conséquence concrète du défaut : les tâches assignées restaient
    // inaccessibles depuis le téléphone.
    [$user, $employee] = accountWithEmployeeOnOtherFarm($this->farm->id, $this->readonlyUser->role_id);

    TaskAssignment::withoutGlobalScopes()->create([
        'farm_id' => $this->farm->id, 'employee_id' => $employee->id,
        'title' => 'Traitement parcelle A', 'category' => 'traitement',
        'scheduled_date' => now()->toDateString(), 'priority' => 'haute',
        'status' => 'a_faire', 'proof_type' => 'aucune',
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/tasks')->assertOk();

    expect(json_encode($response->json()))->toContain('Traitement parcelle A');
});

test('un compte SANS fiche reste sans fiche — le correctif n’invente rien', function () {
    $user = User::factory()->create(['role_id' => $this->readonlyUser->role_id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($user->employee)->toBeNull();
});

/*
 * `employees.user_id` est UNIQUE : un compte n'a au plus qu'UNE fiche. Un test
 * « deux fiches pour un compte » a donc été retiré — le schéma l'interdit, et le
 * départage que j'avais écrit était du code mort. La conséquence utile est
 * ailleurs : si le mobile ne trouve pas la fiche que le web trouve, ce sont DEUX
 * COMPTES différents pour la même personne.
 */

test('deux comptes homonymes : seul celui qui porte le lien a des tâches', function () {
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI',
        'last_name' => 'TOURE', 'first_name' => 'Moussa',
    ]);

    $linked = User::factory()->create(['name' => 'Moussa Touré', 'role_id' => $this->readonlyUser->role_id]);
    $orphan = User::factory()->create(['name' => 'Moussa Touré', 'role_id' => $this->readonlyUser->role_id]);
    $employee->update(['user_id' => $linked->id]);

    expect($linked->fresh()->employee?->id)->toBe($employee->id);
    // Le second compte, homonyme, n'a rien — c'est le symptôme rapporté quand on
    // se connecte au mobile avec celui-là.
    expect($orphan->fresh()->employee)->toBeNull();
});

test('la commande trouve un compte par son NOM, accents indifférents', function () {
    // Défaut corrigé : la première version ne cherchait que dans l'e-mail, donc
    // « touré » ne renvoyait rien alors que le compte existait. On cherche
    // naturellement un agent par son nom.
    $user = User::factory()->create([
        'name' => 'Moussa TOURÉ', 'email' => 'm.t@example.com',
        'role_id' => $this->readonlyUser->role_id,
    ]);

    foreach (['touré', 'toure', 'TOURE', 'moussa'] as $needle) {
        $this->artisan('hr:diagnose-account', ['recherche' => $needle])
            ->expectsOutputToContain('m.t@example.com')
            ->assertSuccessful();
    }

    // L'adresse reste un critère valide.
    $this->artisan('hr:diagnose-account', ['recherche' => 'm.t@example'])
        ->expectsOutputToContain($user->name)
        ->assertSuccessful();
});

test('une recherche sans résultat dit quoi faire ensuite', function () {
    $this->artisan('hr:diagnose-account', ['recherche' => 'zzz-inexistant'])
        ->expectsOutputToContain('SANS argument')
        ->assertFailed();
});

test('la commande de diagnostic nomme la cause', function () {
    [$user, $employee] = accountWithEmployeeOnOtherFarm($this->farm->id, $this->readonlyUser->role_id);

    $this->artisan('hr:diagnose-account', ['recherche' => $user->email])
        ->expectsOutputToContain($user->email)
        ->expectsOutputToContain($employee->employee_id)
        ->assertSuccessful();
});

test('la commande signale un compte sans fiche', function () {
    $user = User::factory()->create(['role_id' => $this->readonlyUser->role_id]);

    $this->artisan('hr:diagnose-account', ['recherche' => $user->email])
        ->expectsOutputToContain('AUCUNE')
        ->assertSuccessful();
});

/*
 * FICHES EN DOUBLE. `users.email` est UNIQUE — deux COMPTES de connexion ne
 * peuvent pas partager une adresse. Mais `employees.email` et le nom ne le sont
 * pas : deux FICHES pour la même personne sont possibles (saisie manuelle puis
 * import, ou une fiche par site). Comme `employees.user_id` EST unique, une
 * seule porte le lien — l'autre paraît « sans accès », et on prend cela pour un
 * conflit de comptes.
 */

test('le diagnostic signale deux fiches pour la même personne', function () {
    $linked = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'last_name' => 'TOURE', 'first_name' => 'Moussa',
        'email' => 'moussa@example.com', 'status' => 'Actif', 'contract_type' => 'CDI',
        'user_id' => $this->readonlyUser->id,
    ]);
    $orphan = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'last_name' => 'TOURE', 'first_name' => 'Moussa',
        'email' => 'moussa@example.com', 'status' => 'Actif', 'contract_type' => 'CDI',
        'user_id' => null,
    ]);

    // expectsOutputToContain consomme les lignes DANS L'ORDRE : une attente par
    // ligne distincte. « AUCUN compte » figure sur la même ligne que la fiche
    // orpheline, donc on l'assure ensemble plutôt que séparément.
    $this->artisan('hr:diagnose-account')
        ->expectsOutputToContain('FICHES EMPLOYÉ en double')
        ->expectsOutputToContain("fiche #{$linked->id}")
        ->expectsOutputToContain("fiche #{$orphan->id}")
        ->expectsOutputToContain('Gardez UNE fiche')
        ->assertSuccessful();

    // Et le marqueur « sans compte » est bien présent quelque part.
    $this->artisan('hr:diagnose-account')
        ->expectsOutputToContain('AUCUN compte')
        ->assertSuccessful();
});

test('déplacer le lien vers la bonne fiche', function () {
    $wrong = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI',
        'user_id' => $this->readonlyUser->id,
    ]);
    $right = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI',
        'user_id' => null,
    ]);

    $this->artisan('hr:relink-account', [
        '--employee' => $right->id, '--user' => $this->readonlyUser->id, '--force' => true,
    ])->assertSuccessful();

    expect($right->fresh()->user_id)->toBe($this->readonlyUser->id);
    // L'ancienne fiche garde son historique : seul l'accès change.
    expect($wrong->fresh()->user_id)->toBeNull();
    expect($wrong->fresh()->exists)->toBeTrue();
});

test('déplacer vers une fiche déjà prise par un AUTRE compte est refusé', function () {
    $other = \App\Models\User::factory()->create(['role_id' => $this->readonlyUser->role_id]);
    $taken = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI',
        'user_id' => $other->id,
    ]);

    $this->artisan('hr:relink-account', [
        '--employee' => $taken->id, '--user' => $this->readonlyUser->id, '--force' => true,
    ])->assertFailed();

    // Rien n'a bougé : la contrainte UNIQUE aurait de toute façon refusé, mais on
    // le dit avant plutôt que de laisser remonter une erreur SQL.
    expect($taken->fresh()->user_id)->toBe($other->id);
});

test('déplacer vers une fiche ARCHIVÉE est refusé', function () {
    // Rattacher un compte à une fiche archivée le laisserait sans tâches : le
    // symptôme qu'on cherche justement à supprimer.
    $archived = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI',
    ]);
    $archived->delete();

    $this->artisan('hr:relink-account', [
        '--employee' => $archived->id, '--user' => $this->readonlyUser->id, '--force' => true,
    ])->assertFailed();
});

test('relier une fiche déjà reliée au même compte ne change rien', function () {
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI',
        'user_id' => $this->readonlyUser->id,
    ]);

    $this->artisan('hr:relink-account', [
        '--employee' => $employee->id, '--user' => $this->readonlyUser->id, '--force' => true,
    ])->expectsOutputToContain('DÉJÀ rattaché')->assertSuccessful();

    expect($employee->fresh()->user_id)->toBe($this->readonlyUser->id);
});
