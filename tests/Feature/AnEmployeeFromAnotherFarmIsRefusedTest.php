<?php

use App\Models\Batch;
use App\Models\Employee;
use App\Models\Farm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'EMPLOYÉ D'UN AUTRE SITE, ACCEPTÉ PAR LE BUREAU.
 *
 * Le terrain valide un employé par la règle des sélecteurs :
 * `Employee::visibleInCurrentFarm()` — rattaché à ce site, ou affecté à lui ce
 * jour (agent prêté), et jamais archivé. Dix-neuf validations du bureau
 * lisaient `exists:employees,id`, qui interroge la table sans aucune
 * borne : l'identifiant d'un employé de n'importe quel site, ou d'un dossier
 * archivé, passait.
 *
 * Mesuré par l'affectation en masse des lots : l'employé d'un autre site
 * devient le responsable de deux lots d'ici, et le message de succès annonce
 * « 2 lot(s) affecté(s) à . » — le nom est vide, puisque le sélecteur, lui, ne
 * le connaît pas.
 *
 * C'est le défaut que `Ferme::existe` a corrigé pour les tables bornées par
 * ferme (lots, stocks, clients…) ; les employés ont leur propre règle, à cause
 * des agents prêtés. Elle vit désormais dans `Ferme::employe()`, lue par les
 * deux portes.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $this->autreSite = Farm::create(['code' => 'SITE-B', 'name' => 'Site B', 'is_active' => true]);
    $this->employeAilleurs = Employee::factory()->create([
        'farm_id' => $this->autreSite->id, 'first_name' => 'Mamadou', 'last_name' => 'Ailleurs',
        'hire_date' => today()->subYear(),
    ]);
    $this->lots = Batch::factory()->count(2)->create(['farm_id' => $this->farm->id, 'employee_id' => null]);
});

test('un lot ne prend pas pour responsable l’employé d’un autre site', function () {
    $this->post(route('batches.bulkAssign'), [
        'batch_ids'   => $this->lots->pluck('id')->all(),
        'employee_id' => $this->employeAilleurs->id,
    ])->assertSessionHasErrors('employee_id');

    expect(Batch::whereNotNull('employee_id')->count())->toBe(0);
});

test('l’agent PRÊTÉ à ce site reste désignable — non-régression', function () {
    $this->employeAilleurs->lendTo($this->farm->id, today()->subDay());

    $this->post(route('batches.bulkAssign'), [
        'batch_ids'   => $this->lots->pluck('id')->all(),
        'employee_id' => $this->employeAilleurs->id,
    ])->assertSessionHasNoErrors();

    expect(Batch::where('employee_id', $this->employeAilleurs->id)->count())->toBe(2);
});

test('aucun formulaire du bureau ne valide un employé sans la règle du site', function () {
    $ecarts = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Http'))) as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }
        // La règle elle-même, écrite dans une chaîne (« 'nullable|exists:employees,id' »
        // ou « 'exists:employees,id' ») — pas un commentaire qui la cite.
        if (preg_match_all("/['|]exists:employees,/", file_get_contents($fichier->getPathname())) > 0) {
            $ecarts[] = str_replace(base_path() . '/', '', $fichier->getPathname());
        }
    }

    expect($ecarts)->toBe([]);
});
