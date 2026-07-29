<?php

use App\Models\Employee;
use App\Models\Farm;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE LISTE DE CATÉGORIES, PAS DEUX.
 *
 * Signalé depuis le terrain : « la liste des catégories n'est pas identique sur
 * le web et mobile ». Le bureau en proposait QUATORZE, groupées (Élevage,
 * Cultures, Relevés) ; l'écran mobile en portait SIX, écrites en dur dans le
 * composant.
 *
 * Deux conséquences opposées, chacune invisible depuis l'autre bout :
 *
 *   • un technicien ne pouvait créer aucune tâche de cultures — arroser se
 *     classait « alimentation », faute de mieux. C'est exactement le défaut
 *     signalé un peu plus tôt côté web, et corrigé là seulement ;
 *   • une tâche de cultures créée au bureau arrivait au téléphone dans une
 *     catégorie qu'il ne savait ni nommer ni illustrer.
 *
 * La liste descend maintenant du serveur avec la session et se met en cache :
 * ajouter une catégorie au bureau la rend disponible au terrain sans republier
 * l'application.
 */

beforeEach(function () {
    $this->setUpRbac();

    DB::table('farm_user')->updateOrInsert(
        ['farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id],
        ['is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    // task.create exige un employé rattaché au compte : sans lui le push répond
    // « permission_denied », et un test qui n'attend que « pas validation_failed »
    // passerait sans avoir rien prouvé.
    Employee::factory()->create([
        'farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id, 'status' => 'Actif',
    ]);
});

test('la session livre les catégories du serveur, toutes', function () {
    $token = $this->adminUser->createToken('mobile')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    $served = collect($response->json('settings.task_categories'));

    expect($served)->toHaveCount(count(TaskTemplate::CATEGORIES))
        ->and($served->pluck('key')->all())->toEqualCanonicalizing(array_keys(TaskTemplate::CATEGORIES));

    // Chaque entrée porte de quoi s'afficher SANS table locale : c'est ce qui
    // évite qu'une seconde liste réapparaisse côté terrain.
    $served->each(fn ($category) => expect($category)->toHaveKeys(['key', 'label', 'emoji', 'group']));

    // Et les cultures en font partie — le manque signalé.
    expect($served->pluck('key'))->toContain('irrigation', 'recolte', 'semis');
});

test('le terrain peut créer une tâche de CULTURES', function () {
    // Le symptôme signalé : « Arrosage » ne pouvait être classé qu'en
    // ALIMENTATION, seule catégorie approchante des six proposées.
    $token = $this->adminUser->createToken('mobile')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Farm-Id', (string) $this->farm->id)
        ->postJson('/api/v1/sync/push', [
            'operations' => [[
                'op_uuid' => (string) Str::uuid(),
                'type'    => 'task.create',
                'payload' => [
                    'uuid'           => (string) Str::uuid(),
                    'title'          => 'Arrosage parcelle A',
                    'category'       => 'irrigation',
                    'scheduled_date' => today()->toDateString(),
                ],
            ]],
        ])->assertOk();

    expect($response->json('results.0.status'))->toBe('success')
        ->and(\App\Models\TaskAssignment::where('title', 'Arrosage parcelle A')->first()?->category)
        ->toBe('irrigation');
});

test('« autre », proposé par le terrain depuis toujours, est RECONNU', function () {
    // Le terrain offrait « autre » sans que le bureau le connaisse : des tâches
    // existent avec cette catégorie. Contraindre la validation sans l'inscrire
    // aurait rejeté du travail déjà saisi — et un refus de synchro est TERMINAL.
    expect(TaskTemplate::CATEGORIES)->toHaveKey('autre');

    $token = $this->adminUser->createToken('mobile')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Farm-Id', (string) $this->farm->id)
        ->postJson('/api/v1/sync/push', [
            'operations' => [[
                'op_uuid' => (string) Str::uuid(),
                'type'    => 'task.create',
                'payload' => [
                    'uuid'           => (string) Str::uuid(),
                    'title'          => 'Divers',
                    'category'       => 'autre',
                    'scheduled_date' => today()->toDateString(),
                ],
            ]],
        ])->assertOk();

    expect($response->json('results.0.status'))->toBe('success');
});

test('une catégorie inventée est refusée, comme au bureau', function () {
    // « string » acceptait n'importe quel libellé : une faute de frappe rendait
    // la tâche invisible de tous les filtres, sans que rien ne le signale.
    $token = $this->adminUser->createToken('mobile')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Farm-Id', (string) $this->farm->id)
        ->postJson('/api/v1/sync/push', [
            'operations' => [[
                'op_uuid' => (string) Str::uuid(),
                'type'    => 'task.create',
                'payload' => [
                    'uuid'           => (string) Str::uuid(),
                    'title'          => 'Tâche mal classée',
                    'category'       => 'arrosaje',
                    'scheduled_date' => today()->toDateString(),
                ],
            ]],
        ])->assertOk();

    expect($response->json('results.0.status'))->toBe('validation_failed')
        ->and($response->json('results.0.errors'))->toHaveKey('category');
});

test('l’écran mobile ne tient plus la liste, il la reçoit', function () {
    $screen = file_get_contents(base_path('mobile/src/features/taches/TachesScreen.tsx'));

    // La liste vient de la session…
    expect($screen)->toContain('me?.settings?.task_categories')
        // …et le repli est nommé comme tel, pour qu'on ne l'y complète pas.
        ->and($screen)->toContain('FALLBACK_CATEGORIES')
        ->and($screen)->not->toContain("const CATEGORIES = [");

    // Groupées comme au bureau : quatorze entrées à plat se parcourent mal.
    expect($screen)->toContain('<optgroup');
});

test('le repli local ne contient QUE des catégories réelles', function () {
    // Un repli qui inventerait une catégorie la ferait refuser à la synchro —
    // le terrain saisirait dans une case que le serveur n'accepte pas.
    $screen = file_get_contents(base_path('mobile/src/features/taches/TachesScreen.tsx'));

    preg_match('/const FALLBACK_CATEGORIES = \[(.*?)\n\]/s', $screen, $matches);
    preg_match_all("/key: '([a-z_]+)'/", $matches[1], $keys);

    expect($keys[1])->not->toBeEmpty();

    foreach ($keys[1] as $key) {
        expect(TaskTemplate::CATEGORIES)->toHaveKey($key);
    }
});

test('la fiche hebdomadaire suit le LIEU DE TRAVAIL', function () {
    // Décision prise avec le promoteur. Les six indicateurs se calculent sur des
    // données déjà bornées au site : chaque ferme voit donc la semaine que
    // l'agent a faite CHEZ ELLE. Un agent partagé a une fiche de chaque côté,
    // chacune juste — ce qu'un rattachement unique au dossier ne saurait pas
    // représenter. La PAIE, elle, ne bouge pas.
    $elsewhere = Farm::firstOrCreate(['code' => 'KER-600'], ['name' => 'Kérouané', 'is_active' => true]);

    $account = User::factory()->create(['role_id' => $this->adminUser->role_id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $account->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $lent = Employee::factory()->create([
        'farm_id' => $elsewhere->id, 'user_id' => $account->id, 'status' => 'Actif',
    ]);

    $lent->lendTo($this->farm->id, today()->subMonth());

    session(['current_farm_id' => $this->farm->id]);

    $response = $this->actingAs($this->adminUser)->get(route('rh.semaine'));
    $response->assertOk();

    expect($response->viewData('employees')->pluck('id'))->toContain($lent->id);

    // Et l'export ne doit pas renvoyer 404 sur une fiche pourtant affichée.
    $this->actingAs($this->adminUser)
        ->get(route('rh.semaine.pdf', ['employee_id' => $lent->id]))
        ->assertOk();
});
