<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA VIGNETTE « EN CONGÉ » COMPTAIT UN STATUT QUE PERSONNE N'ÉCRIT.
 *
 * L'écran de gestion des congés affiche trois compteurs. Celui du milieu :
 *
 *     'on_leave' => $scope(EmployeeLeave::query())->where('status', 'en_cours')->count(),
 *
 * Or `en_cours` n'est JAMAIS écrit sur un congé. Les seules écritures de statut
 * de toute l'application sont `demande` et `approuve` (`storeLeave`, ligne 475 ;
 * `approveLeave`, ligne 525), `refuse` (ligne 550) et `termine` (`endLeave`,
 * ligne 626). Aucune commande planifiée ne fait passer un congé de « approuvé » à
 * « en cours » — il n'y a pas de machine à états qui le prévoie.
 *
 * La vignette affichait donc 0 EN PERMANENCE, pendant que, sur la même page, la
 * ligne juste en dessous est marquée « approuvé », que la fiche de l'agent
 * annonce « Congé », et que la grille de pointage le pré-coche « congé ».
 *
 * ─── POURQUOI LES AUTRES LECTEURS NE SOUFFRAIENT PAS ───
 *
 * Cinq autres endroits lisent `en_cours`, mais toujours comme UN MEMBRE d'une
 * liste — `['approuve', 'en_cours']` (scopeApproved, isActiveOn,
 * Employee::isOnLeaveOn) ou `['approuve', 'en_cours', 'termine']`
 * (PayrollService, leave_days_used). Ils se comportent exactement pareil sans
 * lui. La vignette était la seule à le lire SEUL, donc la seule à dépendre d'une
 * valeur morte.
 *
 * ─── CE QU'ON NE FAIT PAS ───
 *
 * On n'invente pas la machine à états manquante : faire écrire `en_cours` par un
 * traitement planifié changerait le comportement des cinq autres lecteurs pour
 * régler un compteur. La question posée par la vignette est « combien de
 * personnes sont absentes AUJOURD'HUI », et l'application sait déjà y répondre —
 * `EmployeeLeave::isActiveOn()` : un congé validé qui couvre la date. On lui
 * donne son jumeau côté requête, et la vignette l'utilise.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->agent = Employee::factory()->create([
        'status' => 'Actif', 'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);
});

/** Un congé de cet agent, sur cette fenêtre et dans cet état. */
function congePourLaVignette(Employee $agent, int $farmId, string $du, string $au, string $statut): EmployeeLeave
{
    return EmployeeLeave::create([
        'farm_id'     => $farmId,
        'employee_id' => $agent->id,
        'type'        => 'conge_annuel',
        'start_date'  => $du,
        'end_date'    => $au,
        'days_count'  => 3,
        'status'      => $statut,
        'reason'      => 'Congé',
    ]);
}

/** Le compteur « En congé » de l'écran de gestion. */
function vignetteEnConge(object $test): int
{
    return (int) $test->get(route('payroll.leaves'))->assertOk()->viewData('kpi')['on_leave'];
}

test('un congé approuvé COUVRANT aujourd’hui est compté', function () {
    /*
     * LE défaut : la vignette annonçait 0 alors que la ligne juste en dessous
     * était marquée approuvée et que la fiche de l'agent disait « Congé ».
     */
    congePourLaVignette(
        $this->agent, $this->farm->id,
        today()->subDay()->toDateString(),
        today()->addDay()->toDateString(),
        'approuve',
    );

    expect(vignetteEnConge($this))->toBe(1);
});

test('un congé approuvé À VENIR n’est pas encore compté', function () {
    /*
     * LA borne : la vignette dit qui est absent AUJOURD'HUI, pas qui a posé un
     * congé. Sans elle, on remplacerait un compteur toujours nul par un
     * compteur toujours trop haut.
     */
    congePourLaVignette(
        $this->agent, $this->farm->id,
        today()->addDays(10)->toDateString(),
        today()->addDays(15)->toDateString(),
        'approuve',
    );

    expect(vignetteEnConge($this))->toBe(0);
});

test('un congé approuvé DÉJÀ FINI n’est plus compté', function () {
    // L'autre bord de la même fenêtre.
    congePourLaVignette(
        $this->agent, $this->farm->id,
        today()->subDays(15)->toDateString(),
        today()->subDays(10)->toDateString(),
        'approuve',
    );

    expect(vignetteEnConge($this))->toBe(0);
});

test('une simple DEMANDE, non approuvée, n’est pas comptée', function () {
    /*
     * Elle a sa propre vignette (« en attente ») : la compter ici ferait dire à
     * l'écran qu'un agent est absent alors que personne ne l'a autorisé.
     */
    congePourLaVignette(
        $this->agent, $this->farm->id,
        today()->toDateString(),
        today()->addDay()->toDateString(),
        'demande',
    );

    expect(vignetteEnConge($this))->toBe(0);
});

test('un congé REFUSÉ n’est pas compté', function () {
    congePourLaVignette(
        $this->agent, $this->farm->id,
        today()->toDateString(),
        today()->addDay()->toDateString(),
        'refuse',
    );

    expect(vignetteEnConge($this))->toBe(0);
});

test('un congé CLOS par le bouton « Retour » n’est plus compté', function () {
    /*
     * `endLeave` écrit « termine » : l'agent est rentré, il n'est plus absent.
     * C'est le seul statut, avec « approuve », qu'une ligne porte réellement en
     * fin de course.
     */
    congePourLaVignette(
        $this->agent, $this->farm->id,
        today()->subDay()->toDateString(),
        today()->addDay()->toDateString(),
        'termine',
    );

    expect(vignetteEnConge($this))->toBe(0);
});

test('la vignette suit le cycle complet d’un congé — bout en bout', function () {
    /*
     * L'enjeu, par les vraies portes : ce que le bureau voit en faisant les
     * gestes, et non ce qu'une ligne insérée à la main raconte.
     */
    $this->post(route('payroll.leaves.store'), [
        'employee_id' => $this->agent->id,
        'type'        => 'conge_annuel',
        'start_date'  => today()->toDateString(),
        'end_date'    => today()->addDay()->toDateString(),
        'reason'      => 'Congé',
    ]);

    // Saisi par un habilité (rh.S) : approuvé d'emblée, et actif aujourd'hui.
    expect(vignetteEnConge($this))->toBe(1);

    $conge = EmployeeLeave::where('employee_id', $this->agent->id)->firstOrFail();

    $this->post(route('payroll.leaves.end', $conge->id));

    expect(vignetteEnConge($this))->toBe(0);
});

test('les deux autres vignettes ne bougent pas — non-régression', function () {
    // On corrige un compteur, on ne touche pas à ses voisins.
    congePourLaVignette(
        $this->agent, $this->farm->id,
        today()->toDateString(), today()->addDay()->toDateString(), 'demande',
    );

    $kpi = $this->get(route('payroll.leaves'))->assertOk()->viewData('kpi');

    expect((int) $kpi['pending'])->toBe(1)
        ->and((int) $kpi['this_month'])->toBe(1);
});
