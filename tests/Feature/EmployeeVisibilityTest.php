<?php

use App\Models\Employee;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LISTÉ MAIS PAS OUVRABLE — le bug le plus vicieux du module employé.
 *
 * La liste du personnel inclut DÉLIBÉRÉMENT les employés rattachés à un autre
 * site dont le COMPTE a reçu l'accès à la ferme courante : c'est le cas d'un
 * agent prêté d'un site à l'autre, et sans ce volet il obtenait les droits sans
 * apparaître dans la liste RH du site où il travaille.
 *
 * Mais cette règle vivait en dur dans le contrôleur, tandis que show(), edit()
 * et toutes les routes à paramètre {employee} passaient par le global scope de
 * ferme. Résultat : l'employé s'affichait dans la liste, et cliquer dessus
 * donnait « INTROUVABLE » (404 sur /employees/4). Le défaut est invisible en
 * relisant l'un ou l'autre fichier : il naît de leur DÉSACCORD.
 *
 * La règle est désormais portée par le modèle (scopeVisibleInCurrentFarm) et
 * consommée par le binding de route. Ces tests interdisent la re-divergence.
 */

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);
});

/**
 * Un employé rattaché à un AUTRE site, dont le compte a reçu l'accès à la ferme
 * courante. C'est exactement la situation qui produisait le 404.
 */
function lentEmployee(int $currentFarmId): Employee
{
    $otherFarm = Farm::create([
        'name' => 'Kérouané ' . uniqid(), 'code' => 'F-KER-' . uniqid(), 'is_active' => true,
    ]);

    $user = User::factory()->create();

    // Son compte travaille sur le site courant…
    DB::table('farm_user')->insert([
        'farm_id' => $currentFarmId, 'user_id' => $user->id,
        'is_default' => false, 'is_owner' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // …mais sa fiche est rattachée à l'autre site.
    return Employee::factory()->create([
        'farm_id' => $otherFarm->id, 'user_id' => $user->id,
        'status' => 'Actif', 'contract_type' => 'CDI',
    ]);
}

/** Un employé d'un autre site SANS aucun accès au site courant. */
function foreignEmployee(): Employee
{
    $otherFarm = Farm::create(['name' => 'Site tiers ' . uniqid(), 'code' => 'F-TIERS-' . uniqid(), 'is_active' => true]);

    return Employee::factory()->create([
        'farm_id' => $otherFarm->id, 'user_id' => null,
        'status' => 'Actif', 'contract_type' => 'CDI',
    ]);
}

test('un employé prêté par un autre site est listé ET ouvrable', function () {
    $lent = lentEmployee(session('current_farm_id'));

    // Listé — c'était déjà le cas.
    $this->actingAs($this->adminUser)
        ->get(route('employees.index'))
        ->assertOk()
        ->assertSee(e($lent->last_name), false);

    // …et ouvrable : c'est ce qui renvoyait « INTROUVABLE ».
    $this->actingAs($this->adminUser)
        ->get(route('employees.show', $lent->id))
        ->assertOk()
        ->assertSee(e($lent->last_name), false);

    $this->actingAs($this->adminUser)
        ->get(route('employees.edit', $lent->id))
        ->assertOk();
});

test('tout employé listé est ouvrable — aucune exception', function () {
    // La formulation générique du bug : liste et fiche doivent porter la MÊME
    // règle. Un écart réapparaîtrait ici, quel que soit le cas particulier.
    lentEmployee(session('current_farm_id'));
    Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI']);
    foreignEmployee(); // ne doit pas être listé, donc pas testé pour l'ouverture

    $listed = Employee::visibleInCurrentFarm()->pluck('id');
    expect($listed)->not->toBeEmpty();

    foreach ($listed as $id) {
        $this->actingAs($this->adminUser)
            ->get(route('employees.show', $id))
            ->assertOk("L'employé #{$id} apparaît dans la liste mais sa fiche renvoie une erreur.");
    }
});

test('un employé d’un autre site SANS accès reste introuvable', function () {
    // Ouvrir la porte à l'agent prêté ne doit pas l'ouvrir sur les autres sites :
    // la nouvelle règle est plus étroite que le withoutGlobalScopes() d'avant.
    $foreign = foreignEmployee();

    $this->actingAs($this->adminUser)
        ->get(route('employees.index'))
        ->assertOk()
        ->assertDontSee($foreign->last_name, false);

    $this->actingAs($this->adminUser)
        ->get(route('employees.show', $foreign->id))
        ->assertNotFound();

    $this->actingAs($this->adminUser)
        ->get(route('employees.edit', $foreign->id))
        ->assertNotFound();
});

test('les routes dérivées suivent la même règle que la fiche', function () {
    // Le binding {employee} est global : contrats, paie, statut… tout doit
    // s'ouvrir pour un agent prêté, et rester fermé pour un employé étranger.
    $lent = lentEmployee(session('current_farm_id'));
    $foreign = foreignEmployee();

    $this->actingAs($this->adminUser)
        ->get(route('payroll.employee-history', $lent->id))
        ->assertOk();

    $this->actingAs($this->adminUser)
        ->get(route('payroll.employee-history', $foreign->id))
        ->assertNotFound();
});

test('la fiche d’un employé ARCHIVÉ reste consultable', function () {
    // Le dossier d'un sortant doit rester lisible (paie, historique) : le
    // binding inclut donc les archives.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI',
    ]);
    $employee->delete();

    $this->actingAs($this->adminUser)
        ->get(route('employees.show', $employee->id))
        ->assertOk();
});

test('une fiche archivée est refusée à la MODIFICATION, avec un motif', function () {
    // Avant, le scope SoftDeletes renvoyait un 404 muet — indiscernable d'une
    // route cassée. Un refus explicite dit quoi faire.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI',
    ]);
    $employee->delete();

    $this->actingAs($this->adminUser)
        ->get(route('employees.edit', $employee->id))
        ->assertRedirect(route('employees.show', $employee->id))
        ->assertSessionHas('error');

    // Et la porte de service est fermée aussi : l'écran peut être contourné.
    $this->actingAs($this->adminUser)
        ->put(route('employees.update', $employee->id), [
            'last_name' => 'PIRATE', 'first_name' => 'X', 'gender' => 'M',
            'phone' => $employee->phone, 'job_title' => 'X', 'department' => 'Elevage',
            'contract_type' => 'CDI', 'status' => 'Actif', 'hire_date' => now()->toDateString(),
        ])
        ->assertRedirect();

    expect($employee->fresh()->last_name)->not->toBe('PIRATE');
});

test('archiver deux fois est refusé proprement', function () {
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI',
    ]);
    $employee->delete();

    $this->actingAs($this->adminUser)
        ->delete(route('employees.destroy', $employee->id))
        ->assertRedirect();
});

/*
 * VISIBLE MAIS INUTILISABLE — la seconde moitié du défaut « agent prêté ».
 *
 * Rendre sa fiche visible et ouvrable ne suffisait pas : il restait ABSENT de
 * tous les menus déroulants « Responsable », « Superviseur », « Opérateur »,
 * parce qu'ils reposaient sur le global scope de ferme. On le voyait dans
 * l'annuaire du site où il travaille, et on ne pouvait lui attribuer aucune
 * récolte, aucun lot, aucune tâche.
 *
 * La frontière est nette et VOULUE : les sélecteurs d'ATTRIBUTION s'élargissent,
 * la paie et les agrégats RH restent bornés à la ferme — un agent prêté est payé
 * et évalué par son site d'origine.
 */

test('un agent prêté est sélectionnable dans les opérations de la ferme', function () {
    $lent = lentEmployee(session('current_farm_id'));

    // toContain() traite tout argument suivant comme une valeur ATTENDUE de plus,
    // pas comme un message : le contexte reste donc en commentaire.
    // Sans cette ligne : visible dans l'annuaire, absent de tous les sélecteurs.
    expect(Employee::assignableInCurrentFarm()->pluck('id')->all())->toContain($lent->id);
});

test('les formulaires d’opération proposent bien l’agent prêté', function () {
    $lent = lentEmployee(session('current_farm_id'));

    // Un échantillon de formulaires couvrant plusieurs modules : c'est
    // l'attribution du travail, pas de l'argent.
    foreach ([
        'batches.create',       // responsable de lot (élevage)
        'crop-cycles.create',   // responsable de cycle (cultures)
        'tasks.index',          // affectation de tâche (RH opérationnel)
    ] as $routeName) {
        $this->actingAs($this->adminUser)
            ->get(route($routeName))
            ->assertOk()
            ->assertSee(e($lent->last_name), false);
    }
});

test('un employé d’un autre site SANS accès n’est proposé nulle part', function () {
    // Élargir l'attribution ne doit pas ouvrir les sélecteurs sur les autres
    // sites : la règle reste celle de la visibilité, pas « tout le monde ».
    $foreign = foreignEmployee();

    expect(Employee::assignableInCurrentFarm()->pluck('id')->all())->not->toContain($foreign->id);

    $this->actingAs($this->adminUser)
        ->get(route('batches.create'))
        ->assertOk()
        ->assertDontSee($foreign->last_name, false);
});

test('un employé ARCHIVÉ ou inactif n’est proposé nulle part', function () {
    $inactive = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Suspendu', 'contract_type' => 'CDI',
    ]);
    $archived = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI',
    ]);
    $archived->delete();

    $assignable = Employee::assignableInCurrentFarm()->pluck('id')->all();

    expect($assignable)->not->toContain($inactive->id);
    expect($assignable)->not->toContain($archived->id);
});

test('la PAIE reste bornée à la ferme, elle ne suit pas l’attribution', function () {
    // Frontière explicite : un agent prêté travaille ici mais est payé par son
    // site d'origine. Élargir la paie serait une décision financière, pas une
    // correction d'affichage — elle n'est pas prise ici.
    $lent = lentEmployee(session('current_farm_id'));
    $own = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'contract_type' => 'CDI',
    ]);

    $paid = Employee::where('status', 'Actif')->pluck('id')->all();

    expect($paid)->toContain($own->id);
    expect($paid)->not->toContain($lent->id);
});

test('le mobile reçoit la MÊME liste que le web', function () {
    // Une divergence entre les deux supports est le pire des cas : le terrain
    // conclut que « le mobile a perdu des employés ».
    $lent = lentEmployee(session('current_farm_id'));

    expect(Employee::activeForSync()->pluck('id')->sort()->values()->all())
        ->toBe(Employee::assignableInCurrentFarm()->pluck('id')->sort()->values()->all());

    expect(Employee::activeForSync()->pluck('id')->all())->toContain($lent->id);
});
