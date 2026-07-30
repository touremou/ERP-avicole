<?php

use App\Models\Farm;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\ConsolidatedSitesService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DÉSACTIVATION ET VUE CONSOLIDÉE — le passé ne se réécrit pas.
 *
 * Demandé par le promoteur : « on doit tenir compte de la désactivation dans les
 * stats partagées et la vue consolidée, qu'en penses-tu ? »
 *
 * CE QUI ÉTAIT DÉJÀ FAIT : la consolidation excluait bien les sites inactifs.
 *
 * CE QUI NE L'ÉTAIT PAS, et qui est le vrai danger : le filtre portait sur l'état
 * d'AUJOURD'HUI, appliqué à la photo d'une semaine PASSÉE. Désactiver un site en
 * fin de mois effaçait donc sa production des semaines déjà écoulées.
 *
 * Conséquence pour un promoteur qui compare ses mois : une chute apparente de
 * production, provoquée par un geste administratif et non par l'exploitation.
 * Une contre-performance imaginaire — et rien ne l'aurait expliquée, puisque le
 * site aurait simplement disparu du tableau.
 *
 * LA RÈGLE RETENUE : un site figure dans une semaine s'il est ACTIF aujourd'hui
 * — il a sa place même à zéro, c'est un site qu'on pilote — OU s'il a PRODUIT
 * quelque chose cette semaine-là, même désactivé depuis. Et dans ce second cas
 * il est SIGNALÉ, faute de quoi on lirait ses chiffres comme ceux d'un site en
 * service.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->second = Farm::create(['code' => 'CONS-2', 'name' => 'Kérouané', 'is_active' => true]);

    foreach ([$this->farm, $this->second] as $farm) {
        DB::table('farm_user')->updateOrInsert(
            ['farm_id' => $farm->id, 'user_id' => $this->adminUser->id],
            ['is_owner' => true, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]
        );
    }
});

test('un site ACTIF figure dans la consolidation, même sans production', function () {
    // Un site qu'on pilote a sa place dans le tableau, fût-ce à zéro : son absence
    // se lirait comme un oubli, pas comme une semaine creuse.
    $service = app(ConsolidatedSitesService::class);

    $farms = $service->farmsForWeek($this->adminUser, now()->startOfWeek(), now()->endOfWeek());

    expect($farms->pluck('id'))->toContain($this->second->id);
});

test('désactiver un site le retire des semaines À VENIR', function () {
    $this->second->update(['is_active' => false]);

    $farms = app(ConsolidatedSitesService::class)
        ->farmsForWeek($this->adminUser, now()->startOfWeek(), now()->endOfWeek());

    expect($farms->pluck('id'))->not->toContain($this->second->id);
});

test('mais son PASSÉ reste dans la consolidation', function () {
    // Le cœur du sujet. Sans cela, le comparatif accuserait une chute qui n'a pas
    // eu lieu.
    $week = now()->subWeeks(3)->startOfWeek();

    TaskAssignment::create([
        'farm_id' => $this->second->id,
        'scheduled_date' => $week->copy()->addDay()->toDateString(),
        'title' => 'Activité de la semaine', 'category' => 'controle', 'status' => 'a_faire',
    ]);

    $this->second->update(['is_active' => false]);

    $farms = app(ConsolidatedSitesService::class)
        ->farmsForWeek($this->adminUser, $week, $week->copy()->endOfWeek());

    expect($farms->pluck('id'))->toContain($this->second->id);
});

test('un site désactivé SANS production sur la semaine n’y figure pas', function () {
    // La règle n'est pas « on garde tout » : un site sans écriture cette
    // semaine-là n'a rien à y faire, sinon la colonne de zéros ferait croire à une
    // production nulle plutôt qu'à une absence d'activité.
    $this->second->update(['is_active' => false]);

    $week = now()->subWeeks(5)->startOfWeek();

    $farms = app(ConsolidatedSitesService::class)
        ->farmsForWeek($this->adminUser, $week, $week->copy()->endOfWeek());

    expect($farms->pluck('id'))->not->toContain($this->second->id);
});

test('la photo SIGNALE un site désactivé', function () {
    $week = now()->subWeek()->startOfWeek();

    TaskAssignment::create([
        'farm_id' => $this->second->id,
        'scheduled_date' => $week->copy()->addDay()->toDateString(),
        'title' => 'Activité de la semaine', 'category' => 'controle', 'status' => 'a_faire',
    ]);

    $this->second->update(['is_active' => false]);

    $snapshots = app(ConsolidatedSitesService::class)->forUser($this->adminUser, $week);

    $row = collect($snapshots)->firstWhere('farm.id', $this->second->id);

    expect($row)->not->toBeNull()
        ->and($row['inactive'])->toBeTrue();

    // Et le site ACTIF n'est pas marqué.
    $current = collect($snapshots)->firstWhere('farm.id', $this->farm->id);
    expect($current['inactive'])->toBeFalse();
});

test('l’écran affiche la mention « désactivé »', function () {
    $week = now()->subWeek()->startOfWeek();

    TaskAssignment::create([
        'farm_id' => $this->second->id,
        'scheduled_date' => $week->copy()->addDay()->toDateString(),
        'title' => 'Activité de la semaine', 'category' => 'controle', 'status' => 'a_faire',
    ]);

    $this->second->update(['is_active' => false]);

    $this->actingAs($this->adminUser)
        ->get(route('consolide.index', ['week' => $week->format('Y-\WW')]))
        ->assertOk()
        ->assertSee(e(__('désactivé')), false);
});

test('la consolidation reste bornée aux sites de l’utilisateur', function () {
    // Garde-fou : élargir la règle au passé ne doit pas ouvrir les chiffres d'un
    // site auquel on n'a pas accès. Un technicien de Kindia ne voit jamais
    // Kérouané.
    $foreign = Farm::create(['code' => 'CONS-X', 'name' => 'Site tiers', 'is_active' => true]);

    $week = now()->subWeek()->startOfWeek();

    TaskAssignment::create([
        'farm_id' => $foreign->id,
        'scheduled_date' => $week->copy()->addDay()->toDateString(),
        'title' => 'Activité de la semaine', 'category' => 'controle', 'status' => 'a_faire',
    ]);

    $farms = app(ConsolidatedSitesService::class)
        ->farmsForWeek($this->adminUser, $week, $week->copy()->endOfWeek());

    expect($farms->pluck('id'))->not->toContain($foreign->id);
});

test('un utilisateur sans aucun site consolide une liste vide', function () {
    $orphan = User::factory()->create(['role_id' => $this->adminUser->role_id]);

    $farms = app(ConsolidatedSitesService::class)
        ->farmsForWeek($orphan, now()->startOfWeek(), now()->endOfWeek());

    expect($farms)->toBeEmpty();
});
