<?php

/**
 * Cohérence industrielle des permissions (matrice Modules × Rôles).
 *
 * Ces tests verrouillent l'invariant central : « accorder la lecture d'un
 * module dans la matrice suffit à atteindre ce module ». Ils reproduisent
 * les deux régressions signalées en pré-production :
 *   - un opérateur avec depenses.L n'atteignait pas /expenses ;
 *   - un opérateur avec annuaire.L ne voyait pas ses tâches (/tasks),
 *     car TaskController exigeait admin.* au lieu de annuaire.*.
 */

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);
});

/**
 * Crée un rôle dont la matrice n'accorde la lecture QUE sur les slugs donnés.
 * Tous les autres modules restent à zéro (aucun accès), conformément au
 * principe « matrice = seule source de vérité ».
 */
function roleWithReadOn(array $readableSlugs): Role
{
    $role = Role::firstOrCreate(
        ['name' => 'op_test'],
        ['label' => 'Op Test', 'display_name' => 'Op Test', 'permissions' => []]
    );

    $now = now();
    foreach (Module::all() as $module) {
        $canRead = in_array($module->slug, $readableSlugs, true);
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $module->id],
            [
                'can_read'   => $canRead,
                'can_create' => false,
                'can_modify' => false,
                'can_delete' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    return $role;
}

test('un opérateur avec depenses.L atteint le registre des dépenses', function () {
    $user = User::factory()->create(['role_id' => roleWithReadOn(['depenses'])->id]);

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertOk();
});

test('sans depenses.L, le registre des dépenses est refusé', function () {
    $user = User::factory()->create(['role_id' => roleWithReadOn(['elevage'])->id]);

    $response = $this->actingAs($user)->get(route('expenses.index'));
    expect($response->status())->toBeIn([302, 403]);
});

// Les tâches relèvent désormais du module Annuaire/RH (cf. Module::routePrefixMap
// et TaskController). On vérifie l'autorisation au niveau du Gate : le rendu
// HTTP complet de /tasks dépend d'une fonction SQL FIELD() propre à MySQL,
// indisponible sur le SQLite des tests.
test('un opérateur avec annuaire.L est autorisé sur les tâches (route + contrôleur)', function () {
    $user = User::factory()->create(['role_id' => roleWithReadOn(['annuaire'])->id]);

    // Middleware de route (can:L sur tasks.* → annuaire.L) ET contrôleur
    // (Gate::denies('annuaire.L')) reposent sur la même permission.
    expect(\Illuminate\Support\Facades\Gate::forUser($user)->allows('annuaire.L'))->toBeTrue();
});

test('sans annuaire.L, l\'accès aux tâches est refusé', function () {
    $user = User::factory()->create(['role_id' => roleWithReadOn(['planning'])->id]);

    expect(\Illuminate\Support\Facades\Gate::forUser($user)->allows('annuaire.L'))->toBeFalse();
});

test('chaque contrôleur contrôle le MÊME slug que sa route (anti-dérive)', function () {
    // Pour chaque contrôleur faisant un contrôle interne Gate::denies/allows
    // avec un slug en dur, ce slug doit correspondre au module mappé par le
    // préfixe de route du contrôleur (Module::routePrefixMap()). Sinon le
    // middleware de route et le corps du contrôleur exigent deux permissions
    // différentes pour la même fonctionnalité.
    $map = Module::routePrefixMap();

    // Contrôleur → préfixe de route principal (pour résoudre le slug attendu).
    $controllerPrefix = [
        'TaskController'         => 'tasks.',
        'NotificationController' => 'notifications.',
        'ExpenseController'      => 'expenses.',
        'PlanningController'     => 'planning.',
        'SlaughterController'    => 'slaughter.',
        'PayrollController'      => 'payroll.',
        'BatchController'        => 'batches.',
    ];

    $offenders = [];

    foreach ($controllerPrefix as $controller => $prefix) {
        $path = app_path("Http/Controllers/{$controller}.php");
        if (! file_exists($path)) continue;

        $expectedSlug = $map[$prefix] ?? null;
        if (! $expectedSlug) continue;

        $code = file_get_contents($path);
        // Capture tous les slugs utilisés dans Gate::denies('slug.X') / allows.
        preg_match_all("/Gate::(?:denies|allows)\\('([a-z]+)\\.[LCMS]'\\)/", $code, $m);

        foreach (array_unique($m[1]) as $usedSlug) {
            if ($usedSlug !== $expectedSlug) {
                $offenders[] = "{$controller}: utilise '{$usedSlug}.*' mais sa route ({$prefix}) mappe '{$expectedSlug}'.";
            }
        }
    }

    expect($offenders)->toBe([], "Incohérences slug contrôleur/route :\n" . implode("\n", $offenders));
});
