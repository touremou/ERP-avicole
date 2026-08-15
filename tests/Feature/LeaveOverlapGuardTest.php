<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\PayrollPeriod;
use App\Services\PayrollService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA MÊME ABSENCE POUVAIT ÊTRE SAISIE DEUX FOIS — ET ÉTAIT DÉDUITE DEUX FOIS.
 *
 * Rien n'empêchait d'enregistrer deux congés qui se recoupent pour un même
 * agent : le responsable de site saisit l'absence, le bureau la ressaisit, ou un
 * sans-solde se superpose à un congé annuel.
 *
 * Or la paie SOMME les congés qui recoupent la période (PayrollService) :
 *
 *   • les mêmes journées entraient DEUX FOIS dans `daysAbsent` et `unpaidDays`,
 *     donc la retenue pour absence non payée était DOUBLÉE — l'agent était
 *     sous-payé ;
 *   • à l'approbation, le solde de congés annuels était décrémenté DEUX FOIS —
 *     son reliquat était amputé d'autant.
 *
 * Rien ne le signalait, ni à l'agent, ni au promoteur qui vit à l'étranger. Et
 * la paie est le poste où une erreur se répète tous les mois.
 *
 * ─── OÙ LA GARDE VIT, ET POURQUOI DEUX FOIS ───
 *
 * À la SAISIE, évidemment. Mais aussi à l'APPROBATION : une demande peut avoir
 * été déposée avant qu'une autre absence soit approuvée sur les mêmes jours.
 * Contrôler à la seule saisie laisserait passer le doublon par le chemin le plus
 * lent — celui qui attend une décision.
 *
 * ─── CE QU'ON N'INTERDIT PAS ───
 *
 * Deux absences qui se SUIVENT (le lendemain), et une absence sur des jours
 * qu'un congé REFUSÉ couvrait : un refus n'a jamais eu lieu, il n'occupe pas le
 * calendrier.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->employe = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'salary' => 1_300_000,
        'status' => 'Actif', 'annual_leave_balance' => 30,
        // Embauche ancienne et contrat sans terme : la fenêtre contractuelle
        // couvre toute la période, sinon la retenue serait bornée par elle et
        // le test mesurerait autre chose que ce qu'il annonce.
        'hire_date' => now()->subYears(3)->toDateString(),
        'contract_type' => 'CDI', 'contract_end_date' => null,
    ]);
});

/** Dépose une absence par l'écran. */
function saisirAbsence(int $employeId, string $debut, string $fin, string $type = 'sans_solde')
{
    return test()->post(route('payroll.leaves.store'), [
        'employee_id' => $employeId,
        'type'        => $type,
        'start_date'  => $debut,
        'end_date'    => $fin,
    ]);
}

test('une absence qui recoupe une absence existante est refusée', function () {
    // LE défaut : les deux étaient acceptées, et la paie déduisait deux fois.
    saisirAbsence($this->employe->id, '2026-08-10', '2026-08-14')->assertSessionHas('success');

    saisirAbsence($this->employe->id, '2026-08-12', '2026-08-18')->assertSessionHas('error');

    expect(EmployeeLeave::where('employee_id', $this->employe->id)->count())->toBe(1);
});

test('un recouvrement d’UNE SEULE journée compte comme un chevauchement', function () {
    // Les bornes sont incluses : partager le seul 14 août suffit à doubler
    // cette journée dans le calcul.
    saisirAbsence($this->employe->id, '2026-08-10', '2026-08-14');

    saisirAbsence($this->employe->id, '2026-08-14', '2026-08-20')->assertSessionHas('error');

    expect(EmployeeLeave::count())->toBe(1);
});

test('le refus NOMME l’absence en conflit', function () {
    // Un refus muet laisse croire à un bug de saisie.
    saisirAbsence($this->employe->id, '2026-08-10', '2026-08-14', 'conge_annuel');

    saisirAbsence($this->employe->id, '2026-08-12', '2026-08-18')->assertSessionHas('error');

    expect(session('error'))->toContain('10/08/2026')
        ->and(session('error'))->toContain('conge_annuel');
});

test('deux absences qui se SUIVENT restent possibles', function () {
    // On n'interdit pas de poser deux congés consécutifs.
    saisirAbsence($this->employe->id, '2026-08-10', '2026-08-14')->assertSessionHas('success');
    saisirAbsence($this->employe->id, '2026-08-15', '2026-08-20')->assertSessionHas('success');

    expect(EmployeeLeave::count())->toBe(2);
});

test('un congé REFUSÉ n’occupe plus le calendrier', function () {
    // Un refus n'a jamais eu lieu : les jours redeviennent libres.
    saisirAbsence($this->employe->id, '2026-08-10', '2026-08-14');
    EmployeeLeave::first()->update(['status' => 'refuse']);

    saisirAbsence($this->employe->id, '2026-08-12', '2026-08-18')->assertSessionHas('success');

    expect(EmployeeLeave::where('status', '!=', 'refuse')->count())->toBe(1);
});

test('le solde de congés annuels n’est plus décrémenté deux fois', function () {
    // L'autre moitié du dommage : le reliquat de l'agent.
    saisirAbsence($this->employe->id, '2026-08-10', '2026-08-14', 'conge_annuel');
    $apresPremier = $this->employe->fresh()->annual_leave_balance;

    saisirAbsence($this->employe->id, '2026-08-12', '2026-08-16', 'conge_annuel');

    expect($this->employe->fresh()->annual_leave_balance)->toEqual($apresPremier);
});

test('la PAIE ne déduit plus deux fois les mêmes journées', function () {
    /*
     * L'enjeu, mesuré de bout en bout : sans la garde, les deux absences
     * entraient toutes deux dans le calcul et la retenue doublait.
     */
    saisirAbsence($this->employe->id, now()->startOfMonth()->addDays(9)->toDateString(),
        now()->startOfMonth()->addDays(13)->toDateString());

    saisirAbsence($this->employe->id, now()->startOfMonth()->addDays(11)->toDateString(),
        now()->startOfMonth()->addDays(15)->toDateString()); // refusée

    $periode = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Mois courant',
        'year' => (int) now()->year, 'month' => (int) now()->month,
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->toDateString(),
        'status' => 'brouillon',
    ]);

    app(PayrollService::class)->generatePayroll($periode);

    $bulletin = $periode->payslips()->where('employee_id', $this->employe->id)->first();

    // Une seule absence de 5 jours est comptée, pas deux.
    expect($bulletin->days_absent)->toBe(5);
});

test('APPROUVER une demande qui recoupe une absence approuvée est refusé', function () {
    /*
     * Le second chemin. Une demande peut précéder l'approbation d'une autre
     * absence sur les mêmes jours : contrôler à la seule saisie laisserait
     * passer le doublon par le chemin qui attend une décision.
     */
    $demande = EmployeeLeave::create([
        'employee_id' => $this->employe->id, 'farm_id' => $this->farm->id,
        'type' => 'sans_solde', 'start_date' => '2026-09-10', 'end_date' => '2026-09-14',
        'days_count' => 5, 'status' => 'demande',
    ]);

    EmployeeLeave::create([
        'employee_id' => $this->employe->id, 'farm_id' => $this->farm->id,
        'type' => 'conge_annuel', 'start_date' => '2026-09-12', 'end_date' => '2026-09-16',
        'days_count' => 5, 'status' => 'approuve',
    ]);

    $this->post(route('payroll.leaves.approve', $demande))->assertSessionHas('error');

    expect($demande->fresh()->status)->toBe('demande');
});

test('approuver une demande SANS conflit reste possible', function () {
    // Non-régression : le circuit normal n'est pas entravé.
    $demande = EmployeeLeave::create([
        'employee_id' => $this->employe->id, 'farm_id' => $this->farm->id,
        'type' => 'sans_solde', 'start_date' => '2026-09-10', 'end_date' => '2026-09-14',
        'days_count' => 5, 'status' => 'demande',
    ]);

    $this->post(route('payroll.leaves.approve', $demande))->assertSessionHas('success');

    expect($demande->fresh()->status)->toBe('approuve');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * LA MÊME CAUSE, AILLEURS : UN ÉCART DE DATES PRIS À L'ENVERS.
 *
 * `Carbon::diffInDays` rend une valeur SIGNÉE. Partir de la date la plus tardive
 * donne un nombre NÉGATIF — ce que le code d'origine ne prévoyait pas, à deux
 * endroits sans rapport l'un avec l'autre :
 *
 *   • la paie : `max(0, …)` avalait le négatif, donc AUCUN congé n'était compté
 *     (ci-dessus) ;
 *   • les produits finis : un test `<= 3` était TOUJOURS vrai pour une date à
 *     venir, donc tout le stock frais clignotait « périme bientôt ».
 *
 * Les deux se ressemblent par leur silence : l'un rend zéro, l'autre rend vrai.
 * Ni l'un ni l'autre ne PLANTE, et c'est pour cela qu'ils ont duré.
 * ─────────────────────────────────────────────────────────────────────────────
 */

test('un produit qui périme dans trois mois n’est PAS « périme bientôt »', function () {
    // LE défaut : `expiry->diffInDays(now())` valait -90, donc « ≤ 3 » était
    // vrai. Toute la liste des produits finis s'affichait en ambre.
    $produit = new \App\Models\FinishedProduct(['expiry_date' => now()->addDays(90)]);

    expect($produit->is_expiring_soon)->toBeFalse();
});

test('un produit qui périme dans deux jours l’est', function () {
    $produit = new \App\Models\FinishedProduct(['expiry_date' => now()->addDays(2)]);

    expect($produit->is_expiring_soon)->toBeTrue();
});

test('un produit DÉJÀ périmé n’est pas « périme bientôt » — il est périmé', function () {
    // Les deux états sont distincts : l'écran les affiche différemment.
    $produit = new \App\Models\FinishedProduct(['expiry_date' => now()->subDay()]);

    expect($produit->is_expiring_soon)->toBeFalse();
});

test('un produit sans date de péremption ne déclenche rien', function () {
    $produit = new \App\Models\FinishedProduct(['expiry_date' => null]);

    expect($produit->is_expiring_soon)->toBeFalse();
});
