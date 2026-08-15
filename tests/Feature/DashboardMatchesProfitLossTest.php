<?php

use App\Models\Batch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\HealthCheck;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Services\Accounting\PeriodCharges;
use App\Services\DashboardInsightsService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEUX ÉCRANS RÉPONDAIENT À « COMBIEN AI-JE GAGNÉ CE MOIS-CI » — SANS COMPTER
 * LES MÊMES CHARGES.
 *
 *   • le COMPTE DE RÉSULTAT additionne l'achat des animaux, l'aliment consommé,
 *     la santé, LA PAIE, l'eau, l'énergie réseau, le carburant et les dépenses
 *     validées ;
 *   • le TABLEAU DE BORD ne retenait que l'aliment, la santé et les dépenses
 *     validées.
 *
 * Manquaient donc à la marge du tableau de bord LES DEUX PLUS GROS POSTES d'un
 * élevage : la MASSE SALARIALE et l'ACHAT DES BANDES. Sa marge était donc
 * systématiquement plus favorable que la réalité — et c'est l'écran que le
 * promoteur, à l'étranger, regarde EN PREMIER.
 *
 * LE COMMENTAIRE ENTRETENAIT LA CONFUSION. Il affirmait que « la marge nette
 * inclut désormais les dépenses validées (carburant, main d'œuvre…) » : il
 * parlait de la CATÉGORIE DE DÉPENSE « main-d'œuvre journalière », pas des
 * bulletins de paie. Exact au mot près, trompeur à la lecture — le genre de
 * phrase qui empêche de chercher.
 *
 * ─── CE QUE CE TEST GARANTIT ───
 *
 * Non pas que le code se ressemble, mais que les deux écrans DONNENT LE MÊME
 * CHIFFRE sur la même période, sur un jeu de données qui porte chacune des
 * charges oubliées. Une garde de forme (« les deux appellent le même service »)
 * se contourne sans le vouloir ; une égalité de résultat, non.
 *
 * ─── ON N'A PAS ALIGNÉ SUR LE PLUS SIMPLE ───
 *
 * Les deux écrans valorisaient l'aliment différemment : le compte de résultat
 * replie sur le CMP courant quand le coût n'a pas été figé à la saisie, le
 * tableau de bord comptait zéro. C'est la version COMPLÈTE qui devient la règle
 * commune : corriger une divergence en dégradant le chiffre juste aurait été
 * une régression déguisée en correction.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->debut = now()->startOfMonth();
    $this->fin   = now()->endOfMonth();
});

/** Jeu de données portant CHACUNE des charges que le tableau de bord oubliait. */
function chargesDuMois(int $farmId, \App\Models\User $auteur): void
{
    // ACHAT D'ANIMAUX — le poste oublié le plus lourd d'une bande.
    Batch::factory()->create([
        'farm_id' => $farmId,
        'arrival_date' => now()->startOfMonth()->addDay()->toDateString(),
        'total_acquisition_cost' => 4_000_000,
        'status' => 'Actif',
    ]);

    // PAIE — l'autre poste oublié.
    $employe = Employee::factory()->create(['farm_id' => $farmId, 'salary' => 1_200_000]);

    $periode = PayrollPeriod::create([
        'farm_id' => $farmId, 'label' => 'Mois courant',
        'year' => (int) now()->year, 'month' => (int) now()->month,
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->toDateString(),
        'status' => 'valide',
    ]);

    Payslip::create([
        'payroll_period_id' => $periode->id, 'employee_id' => $employe->id,
        'base_salary' => 1_200_000, 'total_primes' => 0, 'total_deductions' => 0,
        'net_salary' => 1_200_000, 'days_worked' => 26, 'days_absent' => 0,
        'days_leave' => 0, 'overtime_hours' => 0, 'payment_status' => 'en_attente',
    ]);

    // SANTÉ et DÉPENSE VALIDÉE — déjà comptées des deux côtés (non-régression).
    HealthCheck::create([
        'farm_id' => $farmId,
        'batch_id' => Batch::first()->id,
        'intervention_date' => now()->startOfMonth()->addDays(2)->toDateString(),
        'type' => 'Vaccin', 'product_name' => 'Newcastle',
        'mode_administration' => 'Eau de boisson', 'cost' => 150_000,
    ]);

    Expense::create([
        'farm_id' => $farmId, 'reference' => 'DEP-90001',
        'user_id' => $auteur->id, 'category' => 'transport', 'label' => 'Transport',
        'amount' => 300_000, 'expense_date' => now()->startOfMonth()->addDays(3)->toDateString(),
        'payment_method' => 'especes', 'status' => 'valide',
    ]);
}

test('la PAIE entre désormais dans les charges du tableau de bord', function () {
    // LE défaut : la masse salariale n'y figurait pas du tout.
    chargesDuMois($this->farm->id, $this->adminUser);

    $financier = (new DashboardInsightsService())->financial($this->debut, $this->fin);

    expect((float) $financier['cost_labor'])->toBe(1200000.0)
        ->and((float) $financier['cost_total'])->toBeGreaterThanOrEqual(1200000.0);
});

test('l’ACHAT DES ANIMAUX y entre aussi', function () {
    chargesDuMois($this->farm->id, $this->adminUser);

    $charges = PeriodCharges::between($this->debut, $this->fin);
    $financier = (new DashboardInsightsService())->financial($this->debut, $this->fin);

    expect((float) $charges['Achats animaux (lots)'])->toBe(4000000.0)
        ->and((float) $financier['cost_total'])->toBeGreaterThanOrEqual(4000000.0);
});

test('les deux écrans annoncent le MÊME total de charges', function () {
    /*
     * Le test qui porte tout le lot : non pas que le code se ressemble, mais que
     * les deux écrans donnent le même chiffre.
     */
    chargesDuMois($this->farm->id, $this->adminUser);

    $financier = (new DashboardInsightsService())->financial($this->debut, $this->fin);
    $resultat  = $this->get(route('reports.profit_loss', [
        'date_from' => $this->debut->toDateString(),
        'date_to'   => $this->fin->toDateString(),
    ]))->assertOk();

    $totalResultat = (float) $resultat->viewData('totalCosts');

    expect((float) $financier['cost_total'])->toBe($totalResultat);
});

test('et donc la même marge', function () {
    chargesDuMois($this->farm->id, $this->adminUser);

    $financier = (new DashboardInsightsService())->financial($this->debut, $this->fin);
    $resultat  = $this->get(route('reports.profit_loss', [
        'date_from' => $this->debut->toDateString(),
        'date_to'   => $this->fin->toDateString(),
    ]))->assertOk();

    // Mêmes produits, mêmes charges → même résultat. (Le compte de résultat
    // ajoute les cycles végétaux CLÔTURÉS ; il n'y en a aucun ici.)
    expect((float) $financier['net_margin'])->toBe((float) $resultat->viewData('netResult'));
});

test('la marge du tableau de bord n’est plus la plus flatteuse des deux', function () {
    // Formulation directe du dommage : avant, elle l'était toujours dès qu'il y
    // avait un salaire ou une bande achetée.
    chargesDuMois($this->farm->id, $this->adminUser);

    $financier = (new DashboardInsightsService())->financial($this->debut, $this->fin);

    // Sans salaire ni achat d'animaux comptés, le total serait resté à
    // 450 000 (santé + transport).
    expect((float) $financier['cost_total'])->toBeGreaterThan(450000.0);
});

test('les conventions du compte de résultat sont conservées', function () {
    /*
     * Trois règles documentées y étaient tenues, et devaient le rester en
     * devenant communes : l'aliment imputé au CONSOMMÉ, les groupes
     * électrogènes EXCLUS de l'énergie (leur gasoil est déjà dans « Carburant »),
     * et le carburant en poste dédié plutôt que dans la ventilation générique.
     */
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path('Services/Accounting/PeriodCharges.php')));

    expect($code)->toContain("!=', 'groupe'")
        ->and($code)->toContain("'carburant'")
        ->and($code)->toContain('feedConsumedCost');
});

test('le repli sur le CMP courant est conservé, pas dégradé', function () {
    /*
     * Les deux écrans valorisaient l'aliment différemment. On aligne sur la
     * version COMPLÈTE — celle du compte de résultat — et non sur la plus
     * simple : corriger une divergence en dégradant le chiffre juste aurait été
     * une régression déguisée en correction.
     */
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path('Services/Accounting/PeriodCharges.php')));

    expect($code)->toContain('cmpByName')
        ->and($code)->toContain('last_unit_price');
});
