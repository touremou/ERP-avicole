<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\Role;
use App\Models\Stock;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\ConsolidatedSitesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * S3 — La vue consolidée multi-sites.
 *
 * Le FarmScope isole chaque ferme, ce qui est CORRECT pour un technicien mais
 * obligeait le promoteur à basculer de site en site. Cette page les met côte à
 * côte.
 *
 * La propriété la plus importante à verrouiller n'est pas l'affichage : c'est le
 * CLOISONNEMENT. Un withoutFarm() nu serait passé silencieusement de « mes deux
 * sites » à « toutes les fermes hébergées » le jour où l'ERP accueille un tiers.
 * Trois tests y sont consacrés, plus un sur la restauration de la ferme courante.
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->managerUser);
});

/** Deuxième site rattaché à l'utilisateur courant (Kérouané). */
function secondSite(bool $owner = true): Farm
{
    $farm = Farm::firstOrCreate(['code' => 'FT-002'], ['name' => 'Kérouané', 'is_active' => true]);

    DB::table('farm_user')->insertOrIgnore([
        'farm_id' => $farm->id, 'user_id' => auth()->id(),
        'is_default' => false, 'is_owner' => $owner, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $farm;
}

/** Ferme d'un TIERS, à laquelle l'utilisateur n'a aucun accès. */
function foreignSite(): Farm
{
    return Farm::firstOrCreate(['code' => 'FT-999'], ['name' => 'Ferme tierce', 'is_active' => true]);
}

/** Exécute un bloc dans le périmètre d'une ferme donnée. */
function onFarm(Farm $farm, callable $callback): mixed
{
    $previous = session('current_farm_id');
    session(['current_farm_id' => $farm->id]);
    try {
        return $callback();
    } finally {
        session(['current_farm_id' => $previous]);
    }
}

function consolidate(): array
{
    return app(ConsolidatedSitesService::class)->forUser(auth()->user(), now()->startOfWeek());
}

// ───────────────── CLOISONNEMENT ─────────────────

test('la consolidation ne porte QUE sur les sites du compte', function () {
    $second = secondSite();
    $foreign = foreignSite();

    onFarm($this->farm, fn () => Batch::factory()->create(['initial_quantity' => 100, 'current_quantity' => 100, 'status' => 'Actif']));
    onFarm($second, fn () => Batch::factory()->create(['initial_quantity' => 200, 'current_quantity' => 200, 'status' => 'Actif']));
    onFarm($foreign, fn () => Batch::factory()->create(['initial_quantity' => 999, 'current_quantity' => 999, 'status' => 'Actif']));

    $sites = consolidate();

    expect($sites)->toHaveCount(2);
    expect(array_map(fn ($s) => $s['farm']->id, $sites))->not->toContain($foreign->id);
    // Les 999 sujets du tiers n'apparaissent nulle part.
    expect(array_sum(array_map(fn ($s) => $s['elevage']['live_subjects'], $sites)))->toBe(300);
});

test('chaque colonne ne contient que les données de SON site', function () {
    $second = secondSite();

    onFarm($this->farm, function () {
        Batch::factory()->create(['initial_quantity' => 500, 'current_quantity' => 500, 'status' => 'Actif']);
        Stock::create(['item_name' => 'Maïs Kindia', 'category' => 'matieres_premieres', 'unit' => 'kg', 'current_quantity' => 1, 'alert_threshold' => 10]);
    });
    onFarm($second, function () {
        Batch::factory()->create(['initial_quantity' => 80, 'current_quantity' => 80, 'status' => 'Actif']);
    });

    $sites = collect(consolidate())->keyBy(fn ($s) => $s['farm']->code);

    expect($sites['FT-001']['elevage']['live_subjects'])->toBe(500);
    expect($sites['FT-002']['elevage']['live_subjects'])->toBe(80);
    // L'article sous seuil de Kindia n'est pas compté à Kérouané.
    expect($sites['FT-001']['stock']['low_items'])->toBe(1);
    expect($sites['FT-002']['stock']['low_items'])->toBe(0);
});

test('la ferme courante est RESTAURÉE après la consolidation', function () {
    secondSite();
    $before = session('current_farm_id');

    consolidate();

    // Sans le finally, l'utilisateur se retrouverait sur l'autre site à la page
    // suivante — un basculement invisible et bien pire qu'une erreur affichée.
    expect(session('current_farm_id'))->toBe($before);
});

test('la ferme courante est restaurée même si le calcul échoue', function () {
    secondSite();
    $before = session('current_farm_id');

    // On force une panne au milieu de la boucle en supprimant la table agrégée.
    DB::statement('DROP TABLE task_assignments');

    try {
        consolidate();
    } catch (\Throwable) {
        // L'exception est attendue ; ce qui compte est l'état de la session.
    }

    expect(session('current_farm_id'))->toBe($before);
});

// ───────────────── ACCÈS ─────────────────

test('le propriétaire d’un site accède à la consolidation', function () {
    secondSite();

    expect(app(ConsolidatedSitesService::class)->canConsolidate($this->managerUser))->toBeTrue();
    $this->get(route('consolide.index'))->assertOk()->assertSee('Les sites côte à côte');
});

test('un technicien multi-sites NON propriétaire est refusé', function () {
    // Rôle sans droit admin, rattaché à deux sites mais sans is_owner.
    $role = Role::create(['name' => 'tech-s3', 'label' => 'Technicien', 'display_name' => 'Technicien', 'permissions' => ['L', 'C']]);
    $this->seedModuleMatrix($role, ['L', 'C']);
    // seedModuleMatrix accorde L/C UNIFORMÉMENT à tous les modules, admin
    // compris. On révoque l'administration : c'est la situation réelle d'un
    // technicien, et c'est elle que la garde doit refuser.
    DB::table('module_permissions')
        ->join('modules', 'modules.id', '=', 'module_permissions.module_id')
        ->where('modules.slug', 'admin')
        ->where('module_permissions.role_id', $role->id)
        ->update(['can_read' => false, 'can_create' => false]);
    $tech = User::factory()->create(['role_id' => $role->id]);
    \Illuminate\Support\Facades\Cache::forget("rbac_perms_{$tech->id}");

    foreach ([$this->farm->id, secondSite()->id] as $farmId) {
        DB::table('farm_user')->insert([
            'farm_id' => $farmId, 'user_id' => $tech->id,
            'is_default' => $farmId === $this->farm->id, 'is_owner' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(app(ConsolidatedSitesService::class)->canConsolidate($tech))->toBeFalse();

    $this->actingAs($tech)
        ->get(route('consolide.index'))
        ->assertRedirect(route('dashboard'));
});

test('l’administrateur accède à la consolidation sans être propriétaire', function () {
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(ConsolidatedSitesService::class)->canConsolidate($this->adminUser))->toBeTrue();
});

test('un site inactif n’apparaît pas dans la consolidation', function () {
    $second = secondSite();
    $second->update(['is_active' => false]);

    $sites = consolidate();

    expect($sites)->toHaveCount(1);
    expect($sites[0]['farm']->id)->toBe($this->farm->id);
});

// ───────────────── INDICATEURS ─────────────────

test('la complétion consolidée se calcule sur les tâches planifiées du site', function () {
    $second = secondSite();
    $today = now()->toDateString();

    onFarm($this->farm, function () use ($today) {
        $emp = Employee::factory()->create(['status' => 'Actif']);
        foreach (['fait', 'fait', 'a_faire', 'a_faire'] as $status) {
            TaskAssignment::create([
                'employee_id' => $emp->id, 'title' => 'T ' . Str::random(4), 'category' => 'controle',
                'scheduled_date' => $today, 'priority' => 'normale', 'status' => $status,
                'completed_at' => $status === 'fait' ? now() : null, 'proof_type' => 'aucune',
            ]);
        }
    });
    onFarm($second, function () use ($today) {
        $emp = Employee::factory()->create(['status' => 'Actif']);
        TaskAssignment::create([
            'employee_id' => $emp->id, 'title' => 'T ' . Str::random(4), 'category' => 'controle',
            'scheduled_date' => $today, 'priority' => 'normale', 'status' => 'fait',
            'completed_at' => now(), 'proof_type' => 'aucune',
        ]);
    });

    $sites = collect(consolidate())->keyBy(fn ($s) => $s['farm']->code);

    expect($sites['FT-001']['tasks']['completion'])->toBe(50.0);
    expect($sites['FT-002']['tasks']['completion'])->toBe(100.0);
});

test('un site sans tâche affiche une complétion NON MESURABLE (pas 0 %)', function () {
    secondSite();

    $sites = collect(consolidate())->keyBy(fn ($s) => $s['farm']->code);

    // 0 % se lirait comme « ce site n'a rien fait » : c'est faux, rien n'était
    // planifié. La distinction évite un appel inutile au technicien.
    expect($sites['FT-002']['tasks']['completion'])->toBeNull();
});

test('c’est la PIRE mortalité du site qui est remontée, pas la moyenne', function () {
    secondSite();

    onFarm($this->farm, function () {
        $calm = Batch::factory()->create(['initial_quantity' => 1000, 'current_quantity' => 995, 'status' => 'Actif']);
        $hit  = Batch::factory()->create(['initial_quantity' => 1000, 'current_quantity' => 900, 'status' => 'Actif']);
        DailyCheck::create(['batch_id' => $calm->id, 'check_date' => now()->toDateString(), 'mortality' => 5]);
        DailyCheck::create(['batch_id' => $hit->id, 'check_date' => now()->toDateString(), 'mortality' => 120]);
    });

    $sites = collect(consolidate())->keyBy(fn ($s) => $s['farm']->code);

    // 12 % (le lot atteint), et non ~6,3 % (la moyenne des deux) : c'est le pire
    // lot qui appelle un appel téléphonique.
    expect($sites['FT-001']['elevage']['worst_mortality'])->toBe(12.0);
});

test('le total du groupe recalcule les taux, il ne moyenne pas des pourcentages', function () {
    $second = secondSite();
    $today = now()->toDateString();

    // Kindia : 1 faite sur 10. Kérouané : 1 faite sur 1.
    onFarm($this->farm, function () use ($today) {
        $emp = Employee::factory()->create(['status' => 'Actif']);
        for ($i = 0; $i < 10; $i++) {
            TaskAssignment::create([
                'employee_id' => $emp->id, 'title' => 'T' . $i, 'category' => 'controle',
                'scheduled_date' => $today, 'priority' => 'normale',
                'status' => $i === 0 ? 'fait' : 'a_faire',
                'completed_at' => $i === 0 ? now() : null, 'proof_type' => 'aucune',
            ]);
        }
    });
    onFarm($second, function () use ($today) {
        $emp = Employee::factory()->create(['status' => 'Actif']);
        TaskAssignment::create([
            'employee_id' => $emp->id, 'title' => 'T', 'category' => 'controle',
            'scheduled_date' => $today, 'priority' => 'normale', 'status' => 'fait',
            'completed_at' => now(), 'proof_type' => 'aucune',
        ]);
    });

    $response = $this->get(route('consolide.index'))->assertOk();
    $totals = $response->viewData('totals');

    // 2 faites sur 11 = 18,2 %. Moyenner les taux des sites donnerait
    // (10 % + 100 %) / 2 = 55 % — un chiffre flatteur et faux.
    expect($totals['tasks_total'])->toBe(11);
    expect($totals['tasks_done'])->toBe(2);
    expect($totals['completion'])->toBe(18.2);
});

test('la page signale un site à AGENT ISOLÉ (pas de contrôle croisé)', function () {
    $second = secondSite();
    $today = now()->toDateString();

    onFarm($second, function () use ($today) {
        $solo = Employee::factory()->create(['status' => 'Actif', 'first_name' => 'Solo']);
        TaskAssignment::create([
            'employee_id' => $solo->id, 'title' => 'T', 'category' => 'controle',
            'scheduled_date' => $today, 'priority' => 'normale', 'status' => 'fait',
            'completed_at' => now(), 'proof_type' => 'aucune',
        ]);
    });

    // Le site le plus exposé au risque d'angle mort doit être signalé comme tel.
    $this->get(route('consolide.index'))
        ->assertOk()
        ->assertSee('agent isolé');
});

test('la complétion par technicien vient du MÊME calcul que sa fiche individuelle', function () {
    $today = now()->toDateString();
    $employee = onFarm($this->farm, function () use ($today) {
        $emp = Employee::factory()->create(['status' => 'Actif']);
        foreach (['fait', 'fait', 'a_faire'] as $status) {
            TaskAssignment::create([
                'employee_id' => $emp->id, 'title' => 'T ' . Str::random(4), 'category' => 'controle',
                'scheduled_date' => $today, 'priority' => 'normale', 'status' => $status,
                'completed_at' => $status === 'fait' ? now() : null, 'proof_type' => 'aucune',
            ]);
        }

        return $emp;
    });
    secondSite();

    $sites = collect(consolidate())->keyBy(fn ($s) => $s['farm']->code);
    $fromConsolidated = collect($sites['FT-001']['team'])->firstWhere('id', $employee->id)['completion'];

    $sheet = app(\App\Services\TechnicianWeekService::class)
        ->forEmployee($employee, now()->startOfWeek());
    $fromSheet = collect($sheet['indicators'])->firstWhere('key', 'completion')['value'];

    // Deux écrans, un seul calcul : sinon le débriefing dérive sur l'outil.
    expect($fromConsolidated)->toBe($fromSheet);
    expect($fromConsolidated)->toBe(66.7);
});
