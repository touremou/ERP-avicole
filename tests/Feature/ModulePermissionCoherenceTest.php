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
test('un opérateur avec rh.L est autorisé sur les tâches (route + contrôleur)', function () {
    // Les tâches relèvent désormais du module RH (cloisonnement Annuaire/RH).
    $user = User::factory()->create(['role_id' => roleWithReadOn(['rh'])->id]);

    // Middleware de route (can:L sur tasks.* → rh.L) ET contrôleur
    // (Gate::denies('rh.L')) reposent sur la même permission.
    expect(\Illuminate\Support\Facades\Gate::forUser($user)->allows('rh.L'))->toBeTrue();
});

test('sans rh.L, l\'accès aux tâches est refusé', function () {
    // Un accès Annuaire (tiers) NE donne PAS accès aux tâches (RH).
    $user = User::factory()->create(['role_id' => roleWithReadOn(['annuaire'])->id]);

    expect(\Illuminate\Support\Facades\Gate::forUser($user)->allows('rh.L'))->toBeFalse();
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

test('aucun module DOUBLON legacy ne subsiste (couvoir/stocks)', function () {
    // Les slugs canoniques sont production/logistique. Les anciens slugs legacy
    // ne doivent plus exister dans la table modules, sous peine de matrice
    // incohérente (permissions accordées sur un module jamais lu).
    // NB : « rh » n'est plus un doublon — c'est un module canonique depuis le
    // cloisonnement Annuaire/RH.
    $legacy = Module::whereIn('slug', ['couvoir', 'stocks'])->pluck('slug')->all();

    expect($legacy)->toBe([], 'Modules doublons encore présents : ' . implode(', ', $legacy));
});

test('la consolidation transfère les permissions du doublon vers le module canonique', function () {
    // Reproduit l'état d'une base ayant subi l'ancien ModuleSeeder : un module
    // doublon « couvoir » distinct de « production », avec un opérateur autorisé
    // à CRÉER (couvoir.C) — droit jamais vu par le code qui contrôle production.*.
    $production = Module::where('slug', 'production')->firstOrFail();

    $couvoir = Module::create([
        'name' => 'Couvoir', 'slug' => 'couvoir', 'icon' => 'fa-egg',
        'color' => 'amber', 'display_order' => 99, 'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['name' => 'op_legacy'],
        ['label' => 'Op Legacy', 'display_name' => 'Op Legacy', 'permissions' => []]
    );

    DB::table('module_permissions')->insert([
        'role_id' => $role->id, 'module_id' => $couvoir->id,
        'can_read' => true, 'can_create' => true, 'can_modify' => false, 'can_delete' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Rejoue la migration de consolidation.
    (require database_path('migrations/2026_06_14_000002_consolidate_legacy_module_duplicates.php'))->up();

    // Le doublon a disparu…
    expect(Module::where('slug', 'couvoir')->exists())->toBeFalse();

    // …et l'opérateur dispose désormais de production.L ET production.C, via le
    // module canonique.
    $perm = DB::table('module_permissions')
        ->where('role_id', $role->id)
        ->where('module_id', $production->id)
        ->first();

    expect((bool) $perm->can_read)->toBeTrue();
    expect((bool) $perm->can_create)->toBeTrue();

    $user = User::factory()->create(['role_id' => $role->id]);
    expect(\Illuminate\Support\Facades\Gate::forUser($user)->allows('production.C'))->toBeTrue();
});
