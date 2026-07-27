<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN SEUL VISAGE PAR PERSONNE.
 *
 * La photo vivait dans deux champs indépendants — `users.avatar_path` pour le
 * compte, `employees.photo_path` pour la fiche RH — sans aucun lien. On voyait
 * donc un visage sur la fiche et un autre sur le téléphone du même agent, et un
 * agent photographié par son responsable apparaissait en initiales sur son
 * propre profil.
 *
 * Deux moitiés au correctif :
 *   - REPLI mutuel dans les accesseurs, sans rien modifier en base ;
 *   - ALIGNEMENT à l'envoi d'une photo, dans les deux sens.
 *
 * Et une règle prudente à la SUPPRESSION : on ne vide l'autre côté que s'il
 * désignait le MÊME fichier — sinon on effacerait une photo distincte, ou on
 * laisserait une référence vers un fichier supprimé, donc une image cassée.
 */

beforeEach(function () {
    Storage::fake('public');
    $this->setUpRbac();
});

/** Un compte et sa fiche, liés. */
function linkedPerson(int $farmId, int $roleId): array
{
    $user = User::factory()->create(['role_id' => $roleId]);
    $employee = Employee::factory()->create([
        'farm_id' => $farmId, 'user_id' => $user->id,
        'status' => 'Actif', 'contract_type' => 'CDI',
    ]);

    return [$user, $employee];
}

test('sans avatar, le compte affiche la photo de la FICHE', function () {
    [$user, $employee] = linkedPerson($this->farm->id, $this->readonlyUser->role_id);
    $employee->update(['photo_path' => 'employees/photos/moussa.jpg']);

    // Sans ce repli, un agent photographié par son responsable apparaissait en
    // initiales sur son propre téléphone.
    expect($user->fresh()->avatar_url)->toContain('moussa.jpg');
});

test('sans photo de fiche, la fiche affiche l’avatar du COMPTE', function () {
    [$user, $employee] = linkedPerson($this->farm->id, $this->readonlyUser->role_id);
    $user->update(['avatar_path' => 'avatars/moussa-selfie.jpg']);

    // La relation doit être chargée : l'accesseur ne déclenche PAS de requête,
    // pour ne pas faire un N+1 sur une liste de cinquante employés.
    $employee->load('user');

    expect($employee->photo_url)->toContain('moussa-selfie.jpg');
});

test('l’accesseur employé ne déclenche AUCUNE requête en liste', function () {
    // Garde-fou de performance : le repli ne doit pas coûter une requête par
    // ligne de la liste d'équipe.
    linkedPerson($this->farm->id, $this->readonlyUser->role_id);
    $employees = Employee::all(); // relation `user` NON chargée

    \Illuminate\Support\Facades\DB::enableQueryLog();
    foreach ($employees as $employee) {
        $employee->photo_url;
    }
    $queries = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    expect($queries)->toBeEmpty();
});

test('aucune photo des deux côtés : les initiales restent', function () {
    [$user, $employee] = linkedPerson($this->farm->id, $this->readonlyUser->role_id);

    // null = le client affiche les INITIALES, plus reconnaissables qu'une
    // silhouette générique. On ne doit donc pas retomber sur le SVG par genre.
    expect($user->fresh()->avatar_url)->toBeNull();
});

test('changer sa photo depuis le profil met à jour la FICHE', function () {
    [$user, $employee] = linkedPerson($this->farm->id, $this->readonlyUser->role_id);
    $employee->update(['photo_path' => 'employees/photos/ancienne.jpg']);

    $this->actingAs($user)
        ->post(route('profile.avatar.update'), ['photo' => UploadedFile::fake()->image('nouvelle.jpg')])
        ->assertRedirect();

    $user->refresh();
    $employee->refresh();

    expect($user->avatar_path)->not->toBeNull();
    expect($employee->photo_path)->toBe($user->avatar_path);
});

test('changer la photo depuis le MOBILE met aussi à jour la fiche', function () {
    [$user, $employee] = linkedPerson($this->farm->id, $this->readonlyUser->role_id);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/avatar', ['photo' => UploadedFile::fake()->image('mobile.jpg')])
        ->assertOk();

    expect($employee->fresh()->photo_path)->toBe($user->fresh()->avatar_path);
});

test('changer la photo sur la FICHE met à jour le compte', function () {
    [$user, $employee] = linkedPerson($this->farm->id, $this->readonlyUser->role_id);
    $user->update(['avatar_path' => 'avatars/ancienne.jpg']);

    app(\App\Actions\Employee\UpdateEmployee::class)->execute(
        $employee,
        ['last_name' => $employee->last_name, 'first_name' => $employee->first_name],
        UploadedFile::fake()->image('fiche.jpg'),
        null,
    );

    // Sans cela, le responsable croirait avoir changé la photo partout alors que
    // l'agent garde l'ancienne sur son téléphone.
    expect($user->fresh()->avatar_path)->toBe($employee->fresh()->photo_path);
});

test('retirer son avatar vide la fiche SI c’était le même fichier', function () {
    [$user, $employee] = linkedPerson($this->farm->id, $this->readonlyUser->role_id);

    $this->actingAs($user)
        ->post(route('profile.avatar.update'), ['photo' => UploadedFile::fake()->image('commune.jpg')]);

    $shared = $user->fresh()->avatar_path;
    expect($employee->fresh()->photo_path)->toBe($shared);

    $this->actingAs($user)->delete(route('profile.avatar.destroy'))->assertRedirect();

    // Laisser photo_path pointer un fichier supprimé donnerait une image cassée
    // sur la fiche.
    expect($user->fresh()->avatar_path)->toBeNull();
    expect($employee->fresh()->photo_path)->toBeNull();
});

test('retirer son avatar NE touche PAS une photo de fiche distincte', function () {
    [$user, $employee] = linkedPerson($this->farm->id, $this->readonlyUser->role_id);
    $user->update(['avatar_path' => 'avatars/selfie.jpg']);
    $employee->update(['photo_path' => 'employees/photos/officielle.jpg']);

    $this->actingAs($user)->delete(route('profile.avatar.destroy'))->assertRedirect();

    // La photo officielle du dossier RH n'appartient pas au compte : la
    // supprimer serait une perte de donnée qu'on n'a pas demandée.
    expect($employee->fresh()->photo_path)->toBe('employees/photos/officielle.jpg');
});

test('un compte SANS fiche employé ne provoque aucune erreur', function () {
    $user = User::factory()->create(['role_id' => $this->readonlyUser->role_id]);

    $this->actingAs($user)
        ->post(route('profile.avatar.update'), ['photo' => UploadedFile::fake()->image('seul.jpg')])
        ->assertRedirect();

    expect($user->fresh()->avatar_path)->not->toBeNull();
});
