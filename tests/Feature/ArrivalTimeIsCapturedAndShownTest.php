<?php

use App\Actions\Hr\RecordAttendance;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'HEURE D'ARRIVÉE ÉTAIT RELEVÉE AU TÉLÉPHONE ET VISIBLE NULLE PART.
 *
 * `employee_attendances.check_in_time` existe depuis la création de la table.
 * Le terrain hors-ligne la remplit : `SyncService::attendanceCreate` la valide
 * (`'rows.*.check_in_time' => 'nullable|date_format:H:i'`) et
 * `RecordAttendance` la persiste.
 *
 * Mais la grille web ne l'envoyait pas, et AUCUN écran ne l'affichait. Une
 * écriture sans lecteur — le miroir exact du défaut inverse (une déclaration
 * lue que personne n'écrit) trouvé ailleurs dans cette campagne.
 *
 * ─── CE QUE ÇA COÛTE ───
 *
 * « Retard » est un statut de la grille. Sans l'heure, c'est une mention sans
 * la pièce qui la justifie : ni l'agent ni le bureau ne peuvent la discuter.
 * Et l'heure relevée au champ, elle, partait en base pour n'être jamais lue.
 *
 * ─── LE PIÈGE DU PRÉ-REMPLISSAGE ───
 *
 * `RecordAttendance` écrit `check_in_time` dès que la clé est présente. Une
 * grille web qui aurait envoyé un champ VIDE aurait donc EFFACÉ, à chaque
 * enregistrement, l'heure relevée au téléphone — et la grille se ré-enregistre
 * des dizaines de fois par mois.
 *
 * Le champ est donc pré-rempli de ce qui est enregistré : ré-enregistrer sans y
 * toucher ne change rien. C'est la même exigence que pour le défaut de statut
 * (#369) — ce que l'écran propose doit dire la vérité sur ce qu'enregistrer va
 * produire.
 *
 * ─── ET UNE CONTRADICTION QU'ON NE LAISSE PLUS S'ÉCRIRE ───
 *
 * Une heure d'arrivée pour quelqu'un qui n'est pas venu n'a pas de sens. Passer
 * « retard » à « absent » laissait derrière lui l'heure qui avait justifié le
 * retard : la ligne disait à la fois qu'il n'était pas venu et à quelle heure
 * il était arrivé.
 *
 * La règle est posée dans `RecordAttendance`, la source partagée, et non dans
 * la grille : le terrain écrit par la même porte, et une règle posée d'un seul
 * côté serait fausse de l'autre.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/** Un agent affectable sur la ferme. */
function agentPointe(int $farmId, string $contrat = 'CDI'): Employee
{
    return Employee::factory()->create([
        'farm_id' => $farmId, 'status' => 'Actif', 'contract_type' => $contrat,
        'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);
}

/** L'heure que la grille propose pour cet agent, à cette date. */
function heureProposee(object $test, int $employeeId, string $date): ?string
{
    foreach ($test->get(route('attendance.index', ['date' => $date]))->assertOk()->viewData('rows') as $ligne) {
        if ($ligne['employee']->id === $employeeId) {
            return $ligne['check_in'];
        }
    }

    return null;
}

/** L'heure enregistrée en base, normalisée en HH:MM. */
function heureEnBase(int $employeeId): ?string
{
    $t = EmployeeAttendance::where('employee_id', $employeeId)->value('check_in_time');

    return $t ? substr((string) $t, 0, 5) : null;
}

test('la grille web enregistre l’heure d’arrivée', function () {
    /*
     * LE défaut : le champ n'existait pas, et « retard » restait une mention
     * sans la pièce qui la justifie.
     */
    $agent = agentPointe($this->farm->id);

    $this->post(route('attendance.store'), [
        'date'          => '2026-06-08',
        'status'        => [$agent->id => 'retard'],
        'check_in_time' => [$agent->id => '09:15'],
    ]);

    expect(heureEnBase($agent->id))->toBe('09:15');
});

test('et la grille la REMONTRE le lendemain', function () {
    // Une donnée saisie qu'aucun écran ne rend n'a pas été saisie.
    $agent = agentPointe($this->farm->id);

    $this->post(route('attendance.store'), [
        'date'          => '2026-06-08',
        'status'        => [$agent->id => 'retard'],
        'check_in_time' => [$agent->id => '09:15'],
    ]);

    expect(heureProposee($this, $agent->id, '2026-06-08'))->toBe('09:15');

    // Dans le CHAMP, pas seulement quelque part sur la page : c'est la valeur
    // que le navigateur renverra au prochain enregistrement.
    $this->get(route('attendance.index', ['date' => '2026-06-08']))
        ->assertSee('value="09:15"', false);
});

test('ré-enregistrer la grille SANS Y TOUCHER n’efface pas l’heure du terrain', function () {
    /*
     * LE piège, et le plus coûteux : `RecordAttendance` écrit dès que la clé est
     * là. Une grille qui renverrait un champ vide effacerait, à chaque
     * enregistrement, ce que le téléphone avait relevé — et la grille se
     * ré-enregistre des dizaines de fois par mois.
     *
     * On éprouve le geste MACHINAL : on relit l'écran et on renvoie exactement
     * ce qu'il propose.
     */
    $agent = agentPointe($this->farm->id);

    // Le terrain a pointé 07:50 hors-ligne.
    app(RecordAttendance::class)->execute('2026-06-08', [
        ['employee_id' => $agent->id, 'status' => 'present', 'check_in_time' => '07:50'],
    ], $this->adminUser->id);

    // Le bureau rouvre la grille et ré-enregistre ce qu'elle propose.
    $lignes = $this->get(route('attendance.index', ['date' => '2026-06-08']))->assertOk()->viewData('rows');

    $statuts = [];
    $heures  = [];
    foreach ($lignes as $ligne) {
        $statuts[$ligne['employee']->id] = $ligne['status'];
        $heures[$ligne['employee']->id]  = $ligne['check_in'];
    }

    $this->post(route('attendance.store'), [
        'date' => '2026-06-08', 'status' => $statuts, 'check_in_time' => $heures,
    ]);

    expect(heureEnBase($agent->id))->toBe('07:50');
});

test('un client qui n’envoie pas le champ n’efface rien non plus', function () {
    /*
     * La borne de l'autre côté : une page en cache, ou un client plus ancien,
     * n'envoie pas la clé. L'absence de clé ne vaut pas effacement.
     */
    $agent = agentPointe($this->farm->id);

    app(RecordAttendance::class)->execute('2026-06-08', [
        ['employee_id' => $agent->id, 'status' => 'present', 'check_in_time' => '07:50'],
    ], $this->adminUser->id);

    $this->post(route('attendance.store'), [
        'date' => '2026-06-08', 'status' => [$agent->id => 'present'],   // pas de check_in_time
    ]);

    expect(heureEnBase($agent->id))->toBe('07:50');
});

test('mais on peut l’EFFACER en vidant le champ', function () {
    // Corriger une heure fausse doit rester possible, sinon la donnée est un piège.
    $agent = agentPointe($this->farm->id);

    app(RecordAttendance::class)->execute('2026-06-08', [
        ['employee_id' => $agent->id, 'status' => 'present', 'check_in_time' => '07:50'],
    ], $this->adminUser->id);

    $this->post(route('attendance.store'), [
        'date' => '2026-06-08',
        'status' => [$agent->id => 'present'],
        'check_in_time' => [$agent->id => ''],
    ]);

    expect(heureEnBase($agent->id))->toBeNull();
});

test('passer un RETARD en ABSENT retire l’heure d’arrivée', function () {
    /*
     * La contradiction qu'on ne laisse plus s'écrire : la ligne disait à la fois
     * qu'il n'était pas venu et à quelle heure il était arrivé.
     */
    $agent = agentPointe($this->farm->id);

    $this->post(route('attendance.store'), [
        'date' => '2026-06-08',
        'status' => [$agent->id => 'retard'],
        'check_in_time' => [$agent->id => '09:15'],
    ]);
    expect(heureEnBase($agent->id))->toBe('09:15');

    $this->post(route('attendance.store'), [
        'date' => '2026-06-08',
        'status' => [$agent->id => 'absent'],
        'check_in_time' => [$agent->id => '09:15'],   // l'écran l'envoie encore
    ]);

    expect(heureEnBase($agent->id))->toBeNull()
        ->and(EmployeeAttendance::where('employee_id', $agent->id)->value('status'))->toBe('absent');
});

test('la règle vaut AUSSI pour le terrain hors-ligne', function () {
    /*
     * Elle est posée dans `RecordAttendance`, la source partagée par les deux
     * portes. Posée dans la grille web seule, elle serait fausse côté mobile —
     * et c'est le mobile qui relève les heures.
     */
    $agent = agentPointe($this->farm->id);

    app(RecordAttendance::class)->execute('2026-06-08', [
        ['employee_id' => $agent->id, 'status' => 'conge', 'check_in_time' => '08:00'],
    ], $this->adminUser->id);

    expect(heureEnBase($agent->id))->toBeNull();
});

test('une heure mal formée est refusée', function () {
    // Le même contrat que le terrain : une heure ou rien, jamais du texte libre.
    $agent = agentPointe($this->farm->id);

    $this->post(route('attendance.store'), [
        'date' => '2026-06-08',
        'status' => [$agent->id => 'present'],
        'check_in_time' => [$agent->id => 'tôt le matin'],
    ])->assertSessionHasErrors('check_in_time.' . $agent->id);

    expect(EmployeeAttendance::count())->toBe(0);
});

test('pointer sans heure reste possible — non-régression', function () {
    /*
     * L'heure est FACULTATIVE. La rendre obligatoire transformerait une grille
     * qui se remplit en trois gestes en une saisie de trente champs, et le
     * pointage cesserait d'être fait.
     */
    $agent = agentPointe($this->farm->id);

    $this->post(route('attendance.store'), [
        'date' => '2026-06-08', 'status' => [$agent->id => 'present'],
    ]);

    expect(EmployeeAttendance::where('employee_id', $agent->id)->value('status'))->toBe('present')
        ->and(heureEnBase($agent->id))->toBeNull();
});

test('le champ est proposé par l’écran — non-régression du rendu', function () {
    // Sans le champ dans le formulaire, tout le reste est inatteignable.
    agentPointe($this->farm->id);

    $this->get(route('attendance.index', ['date' => '2026-06-08']))
        ->assertOk()
        ->assertSee('name="check_in_time[', false);
});
