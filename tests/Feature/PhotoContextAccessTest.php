<?php

use App\Models\Module;
use App\Models\ModulePermission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA PORTE DES PHOTOS NE CONNAISSAIT QUE DEUX MODULES SUR SIX.
 *
 * L'endpoint déclarait six contextes légitimes — incident, dépense, pointage,
 * réception, nettoyage, preuve de tâche — mais n'ouvrait qu'à `elevage.C` ou
 * `abattoir.C`. Le reçu de carburant d'une dépense et la preuve d'une tâche
 * étaient donc refusés à qui n'avait pas de droit d'élevage.
 *
 * ET LE REFUS ÉTAIT SILENCIEUX, ce qui est le vrai défaut. Un téléversement
 * refusé fait sauter l'opération du tour de synchro (mobile/src/offline/sync.ts) :
 * elle reste en file, réessayée à chaque passage, sans jamais apparaître dans
 * « À corriger » ni faire redescendre le compteur d'attente. Le technicien voit
 * « ✓ enregistré », et la dépense n'arrive jamais en comptabilité.
 *
 * Une saisie perdue qui se présente comme réussie est pire qu'un refus net.
 */

beforeEach(function () {
    Storage::fake('public');
    $this->setUpRbac();
});

/** Ne laisse au rôle de l'utilisateur QUE les droits énumérés (slug => niveaux). */
function grantOnly(int $roleId, array $abilities): void
{
    ModulePermission::where('role_id', $roleId)->update([
        'can_read' => false, 'can_create' => false, 'can_modify' => false, 'can_delete' => false,
    ]);

    foreach ($abilities as $slug => $levels) {
        $module = Module::where('slug', $slug)->first();

        if (! $module) {
            return;
        }

        ModulePermission::updateOrCreate(
            ['role_id' => $roleId, 'module_id' => $module->id],
            [
                'can_read'   => str_contains($levels, 'L'),
                'can_create' => str_contains($levels, 'C'),
                'can_modify' => str_contains($levels, 'M'),
                'can_delete' => str_contains($levels, 'S'),
            ]
        );
    }
}

test('un compte qui peut saisir une DÉPENSE peut téléverser son reçu', function () {
    // LE test de régression. Ce compte n'a aucun droit d'élevage : avant, sa photo
    // de reçu était refusée, et sa dépense restait en file pour toujours.
    grantOnly($this->managerUser->role_id, ['depenses' => 'LC']);

    Sanctum::actingAs($this->managerUser->fresh());

    $this->postJson('/api/v1/photos', [
        'photo'   => UploadedFile::fake()->image('recu.jpg'),
        'context' => 'expense',
    ])->assertCreated()
        ->assertJsonStructure(['path', 'url', 'server_time']);
});

test('un compte SANS droit de dépense ne peut pas téléverser un reçu', function () {
    grantOnly($this->managerUser->role_id, ['elevage' => 'LC']);

    Sanctum::actingAs($this->managerUser->fresh());

    $this->postJson('/api/v1/photos', [
        'photo'   => UploadedFile::fake()->image('recu.jpg'),
        'context' => 'expense',
    ])->assertForbidden();
});

test('la preuve de tâche n’exige pas un droit d’écriture d’élevage', function () {
    // La tâche est déjà réservée à son assigné côté terrain. Exiger `elevage.C`
    // en plus fermait la preuve à l'ouvrier à qui la tâche est confiée.
    grantOnly($this->managerUser->role_id, ['elevage' => 'L']);

    Sanctum::actingAs($this->managerUser->fresh());

    $this->postJson('/api/v1/photos', [
        'photo'   => UploadedFile::fake()->image('preuve.jpg'),
        'context' => 'task',
    ])->assertCreated();
});

test('les contextes élevage et abattoir gardent leurs droits d’origine', function () {
    grantOnly($this->managerUser->role_id, ['elevage' => 'LC']);
    Sanctum::actingAs($this->managerUser->fresh());

    $this->postJson('/api/v1/photos', [
        'photo' => UploadedFile::fake()->image('a.jpg'), 'context' => 'incident',
    ])->assertCreated();

    // …et l'abattoir reste fermé à qui n'a que l'élevage : le correctif élargit
    // la porte, il ne l'ouvre pas à tous.
    $this->postJson('/api/v1/photos', [
        'photo' => UploadedFile::fake()->image('b.jpg'), 'context' => 'cleaning',
    ])->assertForbidden();
});

test('un contexte inconnu est refusé par la validation', function () {
    Sanctum::actingAs($this->adminUser);

    $this->postJson('/api/v1/photos', [
        'photo' => UploadedFile::fake()->image('x.jpg'), 'context' => 'contrebande',
    ])->assertStatus(422);
});

test('sans contexte, la photo est traitée comme un incident d’élevage', function () {
    grantOnly($this->managerUser->role_id, ['elevage' => 'LC']);
    Sanctum::actingAs($this->managerUser->fresh());

    $this->postJson('/api/v1/photos', [
        'photo' => UploadedFile::fake()->image('sans-contexte.jpg'),
    ])->assertCreated()
        ->assertJsonPath('path', fn ($p) => str_starts_with($p, 'field/incident/'));
});

test('chaque contexte accepté a un droit déclaré — aucun ne peut être oublié', function () {
    // Le garde-fou : la liste de validation et la table des droits sont la MÊME
    // déclaration. Ajouter un contexte sans lui donner de droit devient impossible
    // — c'est exactement l'écart qui avait produit le défaut.
    $source = file_get_contents(app_path('Http/Controllers/Api/PhotoController.php'));

    expect($source)->toContain('CONTEXT_ABILITIES')
        // La règle `in:` est dérivée de la table, pas recopiée à côté d'elle.
        ->and($source)->toContain("implode(',', array_keys(self::CONTEXT_ABILITIES))")
        // Et l'ancienne porte à deux modules a bien disparu.
        ->and($source)->not->toContain("Gate::denies('elevage.C') && Gate::denies('abattoir.C')");

    $reflection = new ReflectionClass(\App\Http\Controllers\Api\PhotoController::class);
    $abilities = $reflection->getConstant('CONTEXT_ABILITIES');

    expect($abilities)->toHaveKeys(['incident', 'daily_check', 'reception', 'cleaning', 'expense', 'task']);

    foreach ($abilities as $context => $ability) {
        expect($ability)->toMatch('/^[a-z_]+\.[LCMS]$/', "Droit mal formé pour le contexte {$context}");
    }
});
