<?php

use App\Actions\Hr\DecideContract;
use App\Models\Employee;
use App\Models\EmployeeContractEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CONTRAT À DURÉE DÉTERMINÉE — le terme, et la décision qu'il déclenche.
 *
 * `contract_type` acceptait CDD et Journalier sans jamais demander de terme :
 * un contrat à durée déterminée sans durée. Personne n'était donc prévenu de
 * l'échéance, donc personne ne prenait la décision — et un CDD qui court
 * au-delà de son terme sans acte se requalifie.
 *
 * Ces tests verrouillent les trois choses qui manquaient : le terme est exigé
 * quand il doit l'être, la décision est possible et TRACÉE, et l'incohérence
 * entre le formulaire d'embauche et celui de la fiche ne peut pas revenir.
 */

beforeEach(function () {
    $this->setUpRbac();
});

// ── LE TERME EST EXIGÉ ─────────────────────────────────────────────────────

test('un CDD sans date de fin est refusé à l’embauche', function () {
    $this->actingAs($this->adminUser)
        ->post(route('employees.store'), [
            'last_name' => 'Camara', 'first_name' => 'Mamadou', 'gender' => 'M',
            'phone' => '620000001', 'job_title' => 'Ouvrier', 'department' => 'Elevage',
            'contract_type' => 'CDD', 'hire_date' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('contract_end_date');

    expect(Employee::where('phone', '620000001')->exists())->toBeFalse();
});

test('un Journalier sans date de fin est refusé aussi', function () {
    $this->actingAs($this->adminUser)
        ->post(route('employees.store'), [
            'last_name' => 'Bah', 'first_name' => 'Aissatou', 'gender' => 'F',
            'phone' => '620000002', 'job_title' => 'Manœuvre', 'department' => 'Elevage',
            'contract_type' => 'Journalier', 'hire_date' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('contract_end_date');
});

test('un CDI avec une date de fin est refusé : un CDI n’a pas de terme', function () {
    $this->actingAs($this->adminUser)
        ->post(route('employees.store'), [
            'last_name' => 'Diallo', 'first_name' => 'Fatou', 'gender' => 'F',
            'phone' => '620000003', 'job_title' => 'Comptable', 'department' => 'Administration',
            'contract_type' => 'CDI', 'hire_date' => now()->toDateString(),
            'contract_end_date' => now()->addYear()->toDateString(),
        ])
        ->assertSessionHasErrors('contract_end_date');
});

test('un CDD avec un terme valide est embauché et le terme est stocké', function () {
    $end = now()->addMonths(6)->toDateString();

    $this->actingAs($this->adminUser)
        ->post(route('employees.store'), [
            'last_name' => 'Sylla', 'first_name' => 'Ousmane', 'gender' => 'M',
            'phone' => '620000004', 'job_title' => 'Technicien', 'department' => 'Elevage',
            'contract_type' => 'CDD', 'hire_date' => now()->toDateString(),
            'contract_end_date' => $end,
        ])
        ->assertRedirect(route('employees.index'));

    $employee = Employee::where('phone', '620000004')->first();
    expect($employee)->not->toBeNull();
    expect($employee->contract_end_date->toDateString())->toBe($end);
});

test('un terme antérieur à l’embauche est refusé', function () {
    $this->actingAs($this->adminUser)
        ->post(route('employees.store'), [
            'last_name' => 'Toure', 'first_name' => 'Ibrahima', 'gender' => 'M',
            'phone' => '620000005', 'job_title' => 'Gardien', 'department' => 'Elevage',
            'contract_type' => 'CDD',
            'hire_date' => now()->toDateString(),
            'contract_end_date' => now()->subMonth()->toDateString(),
        ])
        ->assertSessionHasErrors('contract_end_date');
});

// ── COHÉRENCE EMBAUCHE / FICHE ─────────────────────────────────────────────

test('les deux formulaires valident EXACTEMENT le même jeu de champs métier', function () {
    // La divergence était invisible : validated() jette silencieusement tout
    // champ non validé. Ce test compare les deux jeux de règles pour que
    // l'oubli d'un champ dans l'un des deux échoue ici, pas en production.
    $employee = Employee::factory()->create(['contract_type' => 'CDI']);

    $store = (new \App\Http\Requests\Employee\StoreEmployeeRequest())->rules();
    // Les règles de mise à jour lisent l'employé de la route (unicité du
    // téléphone hors de soi-même) : on fournit un résolveur minimal.
    $update = tap(new \App\Http\Requests\Employee\UpdateEmployeeRequest(), function ($request) use ($employee) {
        $request->setRouteResolver(fn () => new class($employee) {
            public function __construct(private $employee) {}
            public function parameter($name, $default = null) { return $this->employee; }
        });
    })->rules();

    // Champs propres à un moment du cycle de vie, légitimement asymétriques :
    // le matricule est proposé à l'embauche, le statut RH n'existe qu'après.
    $lifecycle = ['employee_id', 'status'];

    expect(array_values(array_diff(array_keys($store), array_keys($update), $lifecycle)))
        ->toBe([], "Champ(s) validé(s) à l'embauche mais PAS à la mise à jour : la correction serait silencieusement jetée.");

    expect(array_values(array_diff(array_keys($update), array_keys($store), $lifecycle)))
        ->toBe([], "Champ(s) validé(s) à la mise à jour mais PAS à l'embauche.");
});

test('corriger la date d’embauche et le genre sur la fiche prend bien effet', function () {
    // Régression : `hire_date` et `gender` étaient exigés à l'embauche et
    // ABSENTS des règles de mise à jour. Le champ partait à la poubelle sans un
    // mot — une date d'entrée fautive était donc incorrigible.
    $employee = Employee::factory()->create([
        'contract_type' => 'CDI', 'gender' => 'M',
        'hire_date' => '2024-01-15', 'status' => 'Actif',
    ]);

    $this->actingAs($this->adminUser)
        ->put(route('employees.update', $employee), [
            'last_name' => $employee->last_name, 'first_name' => $employee->first_name,
            'phone' => $employee->phone, 'job_title' => $employee->job_title,
            'department' => $employee->department, 'contract_type' => 'CDI',
            'status' => 'Actif', 'gender' => 'F', 'hire_date' => '2023-06-01',
            'orange_money_number' => '620999888',
        ])
        ->assertRedirect();

    $employee->refresh();
    expect($employee->gender)->toBe('F');
    expect($employee->hire_date->toDateString())->toBe('2023-06-01');
    // Affiché dans les deux formulaires, validé dans aucun jusqu'ici.
    expect($employee->orange_money_number)->toBe('620999888');
});

// ── LES DEUX DÉCISIONS ─────────────────────────────────────────────────────

function fixedTermEmployee(array $attributes = []): Employee
{
    return Employee::factory()->create(array_merge([
        'contract_type'     => 'CDD',
        'status'            => 'Actif',
        'hire_date'         => now()->subMonths(3)->toDateString(),
        'contract_end_date' => now()->addDays(10)->toDateString(),
    ], $attributes));
}

test('prolonger repousse le terme et laisse une trace datée', function () {
    $employee = fixedTermEmployee();
    $newEnd = now()->addMonths(6)->startOfDay();

    app(DecideContract::class)->prolong($employee, $newEnd->toDateString(), 'Récolte à finir', $this->adminUser->id);

    $employee->refresh();
    expect($employee->contract_end_date->toDateString())->toBe($newEnd->toDateString());

    $event = EmployeeContractEvent::where('employee_id', $employee->id)->first();
    expect($event->type)->toBe('prolongation');
    expect($event->new_end_date->toDateString())->toBe($newEnd->toDateString());
    // La trace porte l'ANCIEN terme : sans elle, trois prolongations
    // successives seraient indistinguables d'un contrat d'un an.
    expect($event->previous_end_date->toDateString())->toBe(now()->addDays(10)->toDateString());
    expect($event->reason)->toBe('Récolte à finir');
    expect($event->user_id)->toBe($this->adminUser->id);
});

test('prolonger vers une date antérieure au terme actuel est refusé', function () {
    $employee = fixedTermEmployee(['contract_end_date' => now()->addMonths(3)->toDateString()]);

    expect(fn () => app(DecideContract::class)->prolong($employee, now()->addMonth()->toDateString()))
        ->toThrow(ValidationException::class);

    expect($employee->fresh()->contract_end_date->toDateString())
        ->toBe(now()->addMonths(3)->toDateString());
    expect(EmployeeContractEvent::count())->toBe(0);
});

test('un CDI n’a ni terme à prolonger ni préavis à émettre', function () {
    $employee = Employee::factory()->create(['contract_type' => 'CDI', 'status' => 'Actif']);

    expect(fn () => app(DecideContract::class)->prolong($employee, now()->addYear()->toDateString()))
        ->toThrow(ValidationException::class);
    expect(fn () => app(DecideContract::class)->issueNotice($employee))
        ->toThrow(ValidationException::class);
});

test('émettre un préavis date la notification sans sortir l’employé de l’effectif', function () {
    $employee = fixedTermEmployee();

    app(DecideContract::class)->issueNotice($employee, null, 'Fin de saison', $this->adminUser->id);

    $employee->refresh();
    expect($employee->notice_given_at->toDateString())->toBe(now()->toDateString());
    // Il travaille jusqu'à son dernier jour : il doit rester pointé et payé.
    expect($employee->status)->toBe('Actif');

    $event = EmployeeContractEvent::where('employee_id', $employee->id)->first();
    expect($event->type)->toBe('preavis');
});

test('un dernier jour au-delà du terme est refusé — repousser, c’est prolonger', function () {
    $employee = fixedTermEmployee();

    expect(fn () => app(DecideContract::class)->issueNotice($employee, now()->addMonths(2)->toDateString()))
        ->toThrow(ValidationException::class);

    expect($employee->fresh()->notice_given_at)->toBeNull();
});

test('un préavis ne s’émet pas deux fois', function () {
    $employee = fixedTermEmployee();
    $decide = app(DecideContract::class);

    $decide->issueNotice($employee);

    expect(fn () => $decide->issueNotice($employee->fresh()))->toThrow(ValidationException::class);
    expect(EmployeeContractEvent::where('type', 'preavis')->count())->toBe(1);
});

test('prolonger après un préavis lève le préavis', function () {
    $employee = fixedTermEmployee();
    $decide = app(DecideContract::class);

    $decide->issueNotice($employee);
    $decide->prolong($employee->fresh(), now()->addMonths(4)->toDateString());

    // Sinon l'agent resterait « préavis émis » alors qu'il continue : la liste
    // des sorties à venir annoncerait un départ qui n'a pas lieu.
    expect($employee->fresh()->notice_given_at)->toBeNull();
    expect($employee->fresh()->contract_stage)->toBe('en_cours');
});

// ── LE SUIVI ───────────────────────────────────────────────────────────────

test('la liste de suivi ne retient que les contrats à terme sans décision', function () {
    $soon = fixedTermEmployee(['contract_end_date' => now()->addDays(5)->toDateString()]);
    $overdue = fixedTermEmployee(['contract_end_date' => now()->subDays(3)->toDateString()]);
    $far = fixedTermEmployee(['contract_end_date' => now()->addMonths(8)->toDateString()]);
    $cdi = Employee::factory()->create(['contract_type' => 'CDI', 'status' => 'Actif']);
    $decided = fixedTermEmployee(['contract_end_date' => now()->addDays(5)->toDateString()]);
    app(DecideContract::class)->issueNotice($decided);

    $ids = Employee::contractsToDecide(30)->pluck('id')->all();

    expect($ids)->toContain($soon->id);
    // Le terme dépassé est le cas le plus urgent : il ne doit pas sortir de la
    // fenêtre pour la seule raison qu'il est derrière nous.
    expect($ids)->toContain($overdue->id);
    expect($ids)->not->toContain($far->id);
    expect($ids)->not->toContain($cdi->id);
    // Décision prise = plus de rappel : une alerte qui se répète n'est plus lue.
    expect($ids)->not->toContain($decided->id);
});

test('l’état du contrat distingue le terme dépassé de l’échéance proche', function () {
    expect(fixedTermEmployee(['contract_end_date' => now()->subDay()->toDateString()])->contract_stage)->toBe('expire');
    expect(fixedTermEmployee(['contract_end_date' => now()->addDays(7)->toDateString()])->contract_stage)->toBe('a_decider');
    expect(fixedTermEmployee(['contract_end_date' => now()->addMonths(9)->toDateString()])->contract_stage)->toBe('en_cours');
    expect(Employee::factory()->create(['contract_type' => 'CDI'])->contract_stage)->toBe('sans_terme');
});

test('l’écran de suivi affiche les contrats à décider et les décisions possibles', function () {
    $employee = fixedTermEmployee(['contract_end_date' => now()->addDays(4)->toDateString()]);

    $this->actingAs($this->adminUser)
        ->get(route('employees.contracts.index'))
        ->assertOk()
        // La fiche affiche le nom via l'accesseur `name` (patronyme en capitales).
        ->assertSee(strtoupper($employee->last_name), false)
        ->assertSee('Prolonger')
        ->assertSee('préavis', false);
});

test('un lecteur RH consulte le suivi mais ne décide pas', function () {
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->readonlyUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $employee = fixedTermEmployee();

    $this->actingAs($this->readonlyUser)
        ->get(route('employees.contracts.index'))
        ->assertOk();

    // Décider de l'avenir d'un contrat est une modification, pas une lecture.
    $this->actingAs($this->readonlyUser)
        ->post(route('employees.contracts.prolong', $employee), [
            'new_end_date' => now()->addYear()->toDateString(),
        ])
        ->assertRedirect();

    expect(EmployeeContractEvent::count())->toBe(0);
});

test('la commande d’alerte compte les contrats à décider', function () {
    fixedTermEmployee(['contract_end_date' => now()->addDays(3)->toDateString()]);
    fixedTermEmployee(['contract_end_date' => now()->subDays(2)->toDateString()]);
    Employee::factory()->create(['contract_type' => 'CDI', 'status' => 'Actif']);

    $this->artisan('hr:check-contracts')
        ->expectsOutputToContain('2 contrat(s) à terme signalé(s).')
        ->assertSuccessful();
});
