<?php

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Farm;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * AFFECTATION AUX SITES — une relation DATÉE, plus une colonne.
 *
 * Demandé par le promoteur après une journée entière de correctifs sur le
 * « prêt » d'agents : « pouvons-nous prévoir un transfert d'employé entre sites
 * au lieu d'un prêt qui conserve son dossier au site d'origine ? Quelle approche
 * industrielle peut-on mettre en place ? »
 *
 * LE FOND DU PROBLÈME : le prêt n'avait jamais été conçu. « Qui travaille ici »
 * se déduisait de deux faits sans rapport — `employees.farm_id` (où vit le
 * dossier) et l'accès du COMPTE à une autre ferme. Personne n'avait décidé cette
 * combinaison ; elle a donc fui dans une dizaine d'écrans, chacun la
 * redécouvrant à sa façon : sélecteur vide ici, 404 là, garde-fou sauté ailleurs.
 *
 * Une affectation dit QUI travaille OÙ, DEPUIS QUAND, JUSQU'À QUAND et POURQUOI.
 * Mutation et mise à disposition cessent d'être deux mécanismes : elles ne
 * diffèrent que par ce qu'elles font du DOSSIER, donc de la paie.
 */

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);

    $this->otherFarm = Farm::firstOrCreate(
        ['code' => 'KER-AFF'],
        ['name' => 'Kérouané', 'is_active' => true]
    );
});

test('tout dossier créé porte une affectation, quel que soit le chemin', function () {
    // La visibilité repose désormais sur l'affectation. Un agent créé par un
    // écran, un import ou un seeder qui n'en aurait pas serait INVISIBLE partout,
    // sans que rien ne l'explique. La règle vit donc dans le modèle.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'hire_date' => '2025-03-01',
    ]);

    $assignment = $employee->primaryAssignmentOn();

    expect($assignment)->not->toBeNull()
        ->and($assignment->farm_id)->toBe($this->farm->id)
        ->and($assignment->type)->toBe('mutation')
        ->and($assignment->start_date->toDateString())->toBe('2025-03-01')
        ->and($assignment->end_date)->toBeNull();

    expect(Employee::assignableInCurrentFarm()->pluck('id'))->toContain($employee->id);
});

test('une MUTATION déplace le dossier et donc la paie', function () {
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'hire_date' => '2025-01-01',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('employees.transfer', $employee), [
            'farm_id'    => $this->otherFarm->id,
            'start_date' => today()->toDateString(),
            'reason'     => 'Renfort cultures',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $employee->refresh();

    // Le dossier a suivi : c'est ce qui distingue la mutation du prêt.
    expect($employee->farm_id)->toBe($this->otherFarm->id)
        ->and($employee->primaryAssignmentOn()->farm_id)->toBe($this->otherFarm->id);

    // Il n'apparaît plus sur le site de départ…
    expect(Employee::assignableInFarm($this->farm->id)->pluck('id'))->not->toContain($employee->id)
        // …et apparaît sur le site d'arrivée.
        ->and(Employee::assignableInFarm($this->otherFarm->id)->pluck('id'))->toContain($employee->id);
});

test('la mutation CONSERVE le passé au lieu de l’écraser', function () {
    // C'est tout l'apport de la date sur la colonne : une paie de mois à cheval
    // peut savoir où il était, jour par jour. Écraser rendrait la question sans
    // réponse — exactement la limite du modèle précédent.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'hire_date' => '2025-01-01',
    ]);

    $employee->transferTo($this->otherFarm->id, '2026-07-16', 'Mutation');

    $atStart = Employee::visibleInFarm($this->farm->id, '2026-07-05')->pluck('id');
    $atEnd   = Employee::visibleInFarm($this->otherFarm->id, '2026-07-20')->pluck('id');

    expect($atStart)->toContain($employee->id)
        ->and($atEnd)->toContain($employee->id)
        // …et pas l'inverse : le 20, il n'est plus au site de départ.
        ->and(Employee::visibleInFarm($this->farm->id, '2026-07-20')->pluck('id'))
        ->not->toContain($employee->id);

    expect($employee->assignments()->count())->toBe(2);
});

test('une MISE À DISPOSITION laisse le dossier — donc la paie — en place', function () {
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'hire_date' => '2025-01-01',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('employees.lend', $employee), [
            'farm_id'    => $this->otherFarm->id,
            'start_date' => today()->toDateString(),
            'end_date'   => today()->addMonth()->toDateString(),
            'reason'     => 'Appui récolte',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $employee->refresh();

    // Le dossier NE bouge PAS : il reste payé par son site d'origine.
    expect($employee->farm_id)->toBe($this->farm->id);

    // Mais il travaille des DEUX côtés.
    expect(Employee::assignableInFarm($this->farm->id)->pluck('id'))->toContain($employee->id)
        ->and(Employee::assignableInFarm($this->otherFarm->id)->pluck('id'))->toContain($employee->id);
});

test('une mise à disposition EXIGE un terme', function () {
    // Sans terme, un prêt s'oublie et devient une mutation de fait que personne
    // n'a décidée : c'est exactement ce qui s'était produit avec les accès de
    // compte, et ce que ce lot corrige.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('employees.lend', $employee), [
            'farm_id'    => $this->otherFarm->id,
            'start_date' => today()->toDateString(),
        ])
        ->assertSessionHasErrors('end_date');
});

test('une mise à disposition ÉCHUE ne rend plus l’agent affectable', function () {
    // Le terme doit AGIR, sinon l'exiger ne servirait à rien.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'hire_date' => '2025-01-01',
    ]);

    $employee->lendTo($this->otherFarm->id, today()->subMonths(2), today()->subDay());

    expect(Employee::assignableInFarm($this->otherFarm->id)->pluck('id'))->not->toContain($employee->id)
        // …mais il l'était bien pendant la période.
        ->and(Employee::visibleInFarm($this->otherFarm->id, today()->subMonth())->pluck('id'))
        ->toContain($employee->id);
});

test('clore le rattachement PRINCIPAL est refusé, avec un motif', function () {
    // Le clore laisserait l'agent sans site : il disparaîtrait de tous les écrans
    // sans que rien ne l'explique. Pour quitter un site on mute ; pour quitter
    // l'exploitation on archive.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
    ]);

    $primary = $employee->primaryAssignmentOn();

    $this->actingAs($this->adminUser)
        ->post(route('employees.assignment.end', $primary))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($primary->fresh()->end_date)->toBeNull();
});

test('clore une mise à disposition la termine aujourd’hui', function () {
    // Sert surtout aux prêts REPRIS de l'ancien fonctionnement : ils n'ont jamais
    // été décidés, le promoteur doit pouvoir écarter ceux qui ne correspondent
    // à rien.
    $employee = Employee::factory()->create([
        'farm_id' => $this->otherFarm->id, 'status' => 'Actif',
    ]);
    $lending = $employee->lendTo($this->farm->id, today()->subMonth(), today()->addMonth());

    $this->actingAs($this->adminUser)
        ->post(route('employees.assignment.end', $lending))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($lending->fresh()->end_date->toDateString())->toBe(today()->toDateString());
});

test('on ne clôt pas l’affectation d’un agent d’un autre site', function () {
    // L'identifiant est une valeur devinable : le refus ne doit pas dépendre de
    // l'écran qui n'aurait pas affiché le bouton.
    $stranger = Employee::factory()->create([
        'farm_id' => $this->otherFarm->id, 'user_id' => null, 'status' => 'Actif',
    ]);
    $third = Farm::firstOrCreate(['code' => 'TIERS-AFF'], ['name' => 'Tiers', 'is_active' => true]);
    $lending = $stranger->lendTo($third->id, today()->subMonth(), today()->addMonth());

    $this->actingAs($this->adminUser)
        ->post(route('employees.assignment.end', $lending))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($lending->fresh()->end_date->toDateString())->toBe(today()->addMonth()->toDateString());
});

test('muter vers le site où il est DÉJÀ est refusé', function () {
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('employees.transfer', $employee), [
            'farm_id'    => $this->farm->id,
            'start_date' => today()->toDateString(),
        ])
        ->assertSessionHas('error');

    expect($employee->fresh()->assignments()->count())->toBe(1);
});

test('déplacer farm_id directement garde l’affectation ALIGNÉE', function () {
    // Le dossier peut encore bouger par un import ou une correction. Sans
    // alignement, la fiche dirait un site et les sélecteurs un autre : la
    // divergence qu'on vient précisément d'éteindre.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
    ]);

    $employee->update(['farm_id' => $this->otherFarm->id]);

    expect($employee->primaryAssignmentOn()?->farm_id)->toBe($this->otherFarm->id)
        ->and(Employee::assignableInFarm($this->otherFarm->id)->pluck('id'))->toContain($employee->id);
});

test('la reprise a rendu explicite l’ancien fonctionnement, sans rien inventer', function () {
    // La migration crée une affectation principale par dossier, et une mise à
    // disposition par accès de compte à un autre site. Elle marque ces dernières
    // « à confirmer » : elles n'ont jamais été DÉCIDÉES, elles résultaient d'un
    // droit d'accès. On les révèle, on ne les invente pas.
    $migration = file_get_contents(
        base_path('database/migrations/2026_08_12_000000_create_employee_assignments_table.php')
    );

    expect($migration)->toContain("'type'        => 'mutation'")
        ->and($migration)->toContain("'type'        => 'mise_a_disposition'")
        ->and($migration)->toContain('à confirmer')
        // La date d'embauche fait foi : dater la reprise d'aujourd'hui rendrait
        // tout le passé « non affecté », donc invisible des états antérieurs.
        ->and($migration)->toContain('$employee->hire_date ?:');
});

test('la table d’affectation n’est PAS filtrée par ferme', function () {
    // Ce serait circulaire : c'est elle qui définit l'appartenance à une ferme.
    // Filtrée, on ne verrait les affectations d'un site qu'en y étant déjà.
    $model = file_get_contents(app_path('Models/EmployeeAssignment.php'));

    // On vise le TRAIT appliqué, pas le mot : il figure dans le commentaire qui
    // explique justement pourquoi il est absent.
    expect($model)->not->toMatch('/^\s*use BelongsToFarm/m')
        ->and($model)->not->toContain('use App\\Traits\\BelongsToFarm;');
});

test('la fiche employé montre le parcours et permet d’agir', function () {
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
    ]);
    $employee->lendTo($this->otherFarm->id, today()->subDay(), today()->addMonth(), 'Appui récolte');

    $this->actingAs($this->adminUser)
        ->get(route('employees.show', $employee->id))
        ->assertOk()
        ->assertSee(e(__('Affectation aux sites')), false)
        ->assertSee(e($this->otherFarm->name), false)
        ->assertSee(e('Appui récolte'), false);
});
