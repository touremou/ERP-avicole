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

test('la commande de diagnostic nomme la cause', function () {
    [$user, $employee] = accountWithEmployeeOnOtherFarm($this->farm->id, $this->readonlyUser->role_id);

    $this->artisan('hr:diagnose-account', ['email' => $user->email])
        ->expectsOutputToContain($user->email)
        ->expectsOutputToContain($employee->employee_id)
        ->assertSuccessful();
});

test('la commande signale un compte sans fiche', function () {
    $user = User::factory()->create(['role_id' => $this->readonlyUser->role_id]);

    $this->artisan('hr:diagnose-account', ['email' => $user->email])
        ->expectsOutputToContain('AUCUNE')
        ->assertSuccessful();
});
