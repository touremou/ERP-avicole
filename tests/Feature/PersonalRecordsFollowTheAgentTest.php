<?php

use App\Models\Employee;
use App\Models\EmployeeContractEvent;
use App\Models\Farm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CE QUI APPARTIENT À LA PERSONNE SUIT LA PERSONNE, PAS LE SITE.
 *
 * Trois tables portent des faits qui appartiennent à un AGENT : ses congés, ses
 * pointages, et l'historique de ses décisions de contrat. Toutes trois sont
 * cloisonnées par ferme — à raison, car c'est la règle générale de cette base.
 *
 * Mais un agent PRÊTÉ reste visible depuis le site d'accueil
 * (`Employee::visibleInFarm`, adossé aux affectations), et ce qu'on y saisit
 * pour lui porte le `farm_id` du site d'ACCUEIL. Lu depuis son site d'origine,
 * sous le scope de ferme, cela n'existe plus.
 *
 * `leaves()` retirait déjà le scope, et disait pourquoi. `attendances()` a
 * suivi (la paie versait un salaire plein sur des absences constatées
 * ailleurs). Restait l'historique des contrats.
 *
 * ─── POURQUOI CELUI-CI COMPTE ───
 *
 * La migration qui crée `employee_contract_events` nomme son enjeu : « écraser
 * contract_end_date à chaque prolongation effacerait l'historique : on ne
 * saurait plus qu'un CDD a été prolongé trois fois, ce qui est précisément ce
 * qu'un contrôle regarde » — et, plus haut, « c'est un risque juridique, pas
 * une coquetterie de formulaire ».
 *
 * Une trace conservée mais INVISIBLE ne prouve rien de plus qu'une trace
 * absente. Elle est pire : on la croit là.
 *
 * ─── LA BORNE ───
 *
 * Lever le cloisonnement sur la FERME ne lève pas celui sur la PERSONNE. Les
 * relations restent bornées par l'agent — c'est ce qui rend l'ouverture sûre.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->accueil = Farm::create([
        'code' => 'FT-KER', 'name' => 'Kérouané', 'is_active' => true,
    ]);

    $this->agent = Employee::factory()->create([
        'farm_id' => $this->farm->id,
        'status'  => 'Actif',
    ]);
});

/** Une décision de contrat, enregistrée depuis le site voulu. */
function decisionDeContrat(int $farmId, int $employeeId, int $userId, string $type = 'prolongation'): EmployeeContractEvent
{
    return EmployeeContractEvent::withoutGlobalScopes()->create([
        'farm_id'     => $farmId,
        'employee_id' => $employeeId,
        'type'        => $type,
        'decided_on'  => today()->toDateString(),
        'user_id'     => $userId,
    ]);
}

test('une décision prise sur le site d’ACCUEIL reste au dossier', function () {
    /*
     * LE défaut : l'événement existait en base, le dossier n'en voyait aucun.
     */
    decisionDeContrat($this->accueil->id, $this->agent->id, $this->adminUser->id);

    expect(EmployeeContractEvent::withoutGlobalScopes()->count())->toBe(1)
        ->and($this->agent->contractEvents()->count())->toBe(1);
});

test('l’historique réunit les décisions des DEUX sites', function () {
    /*
     * Un CDD prolongé une fois ici, une fois là-bas : trois prolongations
     * comptées comme une seule seraient exactement ce que le contrôle cherche.
     */
    decisionDeContrat($this->farm->id, $this->agent->id, $this->adminUser->id);
    decisionDeContrat($this->accueil->id, $this->agent->id, $this->adminUser->id);
    decisionDeContrat($this->accueil->id, $this->agent->id, $this->adminUser->id, 'preavis');

    expect($this->agent->contractEvents()->count())->toBe(3);
});

test('le dossier d’un COLLÈGUE reste séparé', function () {
    /*
     * LA borne. Lever le cloisonnement sur la ferme ne lève pas celui sur la
     * personne — sinon on aurait remplacé une trace incomplète par une fuite
     * entre dossiers.
     */
    $collegue = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
    ]);

    decisionDeContrat($this->accueil->id, $collegue->id, $this->adminUser->id);

    expect($this->agent->contractEvents()->count())->toBe(0)
        ->and($collegue->contractEvents()->count())->toBe(1);
});

test('l’ordre antichronologique est conservé', function () {
    // Le retrait du scope ne doit pas emporter le tri : l'écran lit la dernière
    // décision en tête.
    $ancienne = decisionDeContrat($this->accueil->id, $this->agent->id, $this->adminUser->id);
    $ancienne->forceFill(['decided_on' => today()->subYear()->toDateString()])->save();

    $recente = decisionDeContrat($this->farm->id, $this->agent->id, $this->adminUser->id, 'preavis');

    expect($this->agent->contractEvents()->first()->id)->toBe($recente->id);
});
