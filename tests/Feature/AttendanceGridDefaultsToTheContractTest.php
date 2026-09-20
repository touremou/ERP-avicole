<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA GRILLE PRÉ-COCHAIT « PRÉSENT » POUR UN JOURNALIER QUI N'ÉTAIT PAS VENU.
 *
 * `AttendanceController::index` pose le même défaut pour tout le monde :
 *
 *     $status = $existing[$emp->id]->status
 *         ?? ($onLeave->has($emp->id) ? 'conge' : 'present');
 *
 * C'est le bon geste pour un CDI ou un CDD : ils sont attendus, l'opérateur ne
 * coche que les écarts, et enregistrer sans rien changer dit la vérité.
 *
 * Pour un JOURNALIER, c'est l'inverse. Il n'est pas attendu : il vient. Un défaut
 * « présent » fait d'un enregistrement machinal une déclaration de journée
 * travaillée — et depuis que la paie lit ces journées (cf. la correction du
 * régime journalier), cette déclaration est PAYÉE.
 *
 * ─── CE QUE ÇA COÛTE ───
 *
 * L'opérateur ouvre la grille, coche les trois journaliers venus ce matin,
 * enregistre. Les sept autres, qui ne sont pas venus, sont enregistrés
 * « présent » parce que c'est ce que l'écran proposait. Sept journées dues
 * qu'aucune main n'a voulu déclarer.
 *
 * Le défaut est d'autant plus sournois que le geste correct — ne rien faire pour
 * ceux qui ne sont pas venus — produisait le pire résultat possible.
 *
 * ─── LA RÈGLE, ET SA SYMÉTRIE ───
 *
 * Le défaut de l'écran suit le régime du contrat, comme la paie et le rapport :
 *
 *   • CDI / CDD  → « présent ». On coche les écarts.
 *   • JOURNALIER → « absent ». On coche les journées faites.
 *
 * Un congé validé garde son pré-remplissage « congé » dans les deux régimes :
 * c'est un fait déjà établi ailleurs, pas une présomption.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/**
 * Un agent sous ce type de contrat, affectable sur la ferme donnée.
 *
 * La ferme est passée EXPLICITEMENT : un assistant Pest est une fonction
 * globale, il ne voit pas `$this->farm` du cas de test.
 */
function agentDeLaGrille(int $farmId, string $typeDeContrat): Employee
{
    return Employee::factory()->create([
        'farm_id'           => $farmId,
        'status'            => 'Actif',
        'contract_type'     => $typeDeContrat,
        'hire_date'         => '2024-01-15',
        'contract_end_date' => null,
    ]);
}

/** Le statut que la grille propose pour cet agent, à cette date. */
function statutPropose(object $test, int $employeeId, string $date): ?string
{
    $lignes = $test->get(route('attendance.index', ['date' => $date]))
        ->assertOk()
        ->viewData('rows');

    foreach ($lignes as $ligne) {
        if ($ligne['employee']->id === $employeeId) {
            return $ligne['status'];
        }
    }

    return null;
}

test('la grille propose « absent » pour un journalier', function () {
    /*
     * LE défaut : elle proposait « présent », et enregistrer sans rien changer
     * déclarait — donc faisait payer — une journée que personne n'avait vue.
     */
    $journalier = agentDeLaGrille($this->farm->id, 'Journalier');

    expect(statutPropose($this, $journalier->id, '2026-06-08'))->toBe('absent');
});

test('et « présent » pour un CDI — non-régression', function () {
    /*
     * LA borne de l'autre côté, et le cas de très loin le plus courant : un
     * permanent est attendu, l'opérateur ne coche que les écarts.
     */
    $permanent = agentDeLaGrille($this->farm->id, 'CDI');

    expect(statutPropose($this, $permanent->id, '2026-06-08'))->toBe('present');
});

test('un CDD est proposé présent lui aussi — non-régression', function () {
    $cdd = agentDeLaGrille($this->farm->id, 'CDD');

    expect(statutPropose($this, $cdd->id, '2026-06-08'))->toBe('present');
});

test('un pointage DÉJÀ SAISI est rendu tel quel — non-régression', function () {
    /*
     * La règle du défaut ne s'applique qu'en l'absence de saisie : ce qui a été
     * enregistré prime, quel que soit le contrat. Sans cela, rouvrir la grille
     * effacerait le travail de la veille.
     */
    $journalier = agentDeLaGrille($this->farm->id, 'Journalier');

    EmployeeAttendance::create([
        'farm_id'         => $this->farm->id,
        'employee_id'     => $journalier->id,
        'attendance_date' => '2026-06-08',
        'status'          => 'present',
    ]);

    expect(statutPropose($this, $journalier->id, '2026-06-08'))->toBe('present');
});

test('un CONGÉ validé prime sur le régime — non-régression', function () {
    /*
     * Un congé approuvé est un fait déjà établi, pas une présomption : il garde
     * son pré-remplissage dans les DEUX régimes.
     */
    $journalier = agentDeLaGrille($this->farm->id, 'Journalier');

    EmployeeLeave::create([
        'farm_id'     => $this->farm->id,
        'employee_id' => $journalier->id,
        'type'        => 'conge_annuel',
        'start_date'  => '2026-06-08',
        'end_date'    => '2026-06-10',
        'days_count'  => 3,
        'status'      => 'approuve',
    ]);

    expect(statutPropose($this, $journalier->id, '2026-06-08'))->toBe('conge');
});

test('le geste machinal ne déclare plus de journée pour un journalier', function () {
    /*
     * L'enjeu, de bout en bout : c'est le geste NORMAL — ouvrir, cocher ceux qui
     * sont venus, enregistrer — qui produisait le mauvais résultat pour tous les
     * autres. On l'éprouve par la vraie porte.
     */
    $venu     = agentDeLaGrille($this->farm->id, 'Journalier');
    $pasVenu  = agentDeLaGrille($this->farm->id, 'Journalier');

    $lignes = $this->get(route('attendance.index', ['date' => '2026-06-08']))
        ->assertOk()->viewData('rows');

    // L'opérateur ne change QUE la ligne de celui qui est venu.
    $charge = [];
    foreach ($lignes as $ligne) {
        $charge[$ligne['employee']->id] = $ligne['employee']->id === $venu->id
            ? 'present'
            : $ligne['status'];
    }

    $this->post(route('attendance.store'), ['date' => '2026-06-08', 'status' => $charge]);

    expect(EmployeeAttendance::where('employee_id', $venu->id)->value('status'))->toBe('present')
        ->and(EmployeeAttendance::where('employee_id', $pasVenu->id)->value('status'))->toBe('absent');
});
