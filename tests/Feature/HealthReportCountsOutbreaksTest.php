<?php

use App\Models\Batch;
use App\Models\HealthCheck;
use App\Models\HealthIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE RAPPORT QUI CHIFFRE LA SANTÉ IGNORAIT LES ÉPIDÉMIES.
 *
 * « Rapport Santé & Finance » : coût sanitaire total, coût par tête, structure
 * des coûts, classement des lots. Tout y était calculé sur les seuls ACTES du
 * registre (vaccins, traitements, vitamines, désinfections).
 *
 * Le coût de traitement d'un INCIDENT — l'événement le plus cher, celui qu'on
 * ouvre ce rapport pour comprendre — n'y entrait pas. Le titre de l'écran, « coût
 * sanitaire par tête », était sous-estimé de ce qui coûte le plus.
 *
 * ─── TROISIÈME ENDROIT, TROISIÈME RÉSULTAT ───
 *
 * La marge du lot comptait actes + incidents. Le compte de résultat ne comptait
 * que les actes (corrigé juste avant). Ce rapport-ci, que les actes. Et son
 * gabarit recomposait ENCORE le coût par lot à sa façon, si bien que le tableau
 * pouvait ne pas s'accorder avec son propre total.
 *
 * Le coût d'un lot est désormais calculé UNE fois, au contrôleur, et le gabarit
 * l'affiche. Les postes affichés viennent aussi du contrôleur : la liste était
 * en dur dans la vue, qui n'aurait donc jamais montré la ligne des incidents —
 * et les pourcentages, calculés sur un total qui l'inclut, n'auraient plus fait
 * 100 %.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'initial_quantity' => 1000,
    ]);
});

/** Un acte du registre sanitaire. */
function acte(int $farmId, Batch $lot, string $type, float $cout, int $userId): HealthCheck
{
    return HealthCheck::create([
        'farm_id'             => $farmId,
        'batch_id'            => $lot->id,
        'type'                => $type,
        'product_name'        => 'Produit ' . $type,
        'mode_administration' => 'Eau de boisson',
        'intervention_date'   => now()->toDateString(),
        'cost'                => $cout,
        'user_id'             => $userId,
    ]);
}

/** Un incident sanitaire diagnostiqué et chiffré. */
function incident(int $farmId, Batch $lot, float $cout, int $userId, ?string $date = null): HealthIncident
{
    return HealthIncident::create([
        'farm_id'           => $farmId,
        'user_id'           => $userId,
        'building_id'       => $lot->building_id,
        'batch_id'          => $lot->id,
        'incident_date'     => $date ?? now()->toDateString(),
        'mortality_count'   => 30,
        'symptoms'          => 'Toux, prostration',
        'status'            => HealthIncident::STATUS_DIAGNOSED,
        'severity'          => HealthIncident::SEVERITY_CRITICAL,
        'suspected_disease' => 'Newcastle',
        'diagnosed_at'      => now(),
        'treatment_cost'    => $cout,
    ]);
}

/** Ouvre le rapport Santé & Finance. */
function rapportSante(array $params = [])
{
    return test()->get(route('reports.health_finance', $params))->assertOk();
}

test('le coût d’une épidémie entre au coût sanitaire total', function () {
    /*
     * LE défaut, chiffré : 500 000 d'actes visibles, 2 000 000 d'épidémie
     * invisibles.
     */
    acte($this->farm->id, $this->lot, 'Vaccin', 500_000, $this->adminUser->id);
    incident($this->farm->id, $this->lot, 2_000_000, $this->adminUser->id);

    expect(rapportSante()->viewData('totalGlobalCost'))->toBe(2500000.0);
});

test('le coût PAR TÊTE, titre de l’écran, cesse d’être sous-estimé', function () {
    // 2 500 000 sur 1 000 sujets = 2 500, et non 500.
    acte($this->farm->id, $this->lot, 'Vaccin', 500_000, $this->adminUser->id);
    incident($this->farm->id, $this->lot, 2_000_000, $this->adminUser->id);

    expect(rapportSante()->viewData('averageCostPerHead'))->toBe(2500.0);
});

test('les incidents ont leur propre poste dans la structure des coûts', function () {
    /*
     * Ils ne viennent pas du registre des actes : les fondre dans « Traitement »
     * les rendrait indiscernables d'une prophylaxie programmée, alors que
     * l'intérêt du rapport est justement de les distinguer.
     */
    acte($this->farm->id, $this->lot, 'Vaccin', 500_000, $this->adminUser->id);
    incident($this->farm->id, $this->lot, 2_000_000, $this->adminUser->id);

    $vue = rapportSante();

    expect($vue->viewData('typeBreakdown'))->toHaveKey('Incident sanitaire')
        ->and($vue->viewData('typeBreakdown')['Incident sanitaire'])->toBe(2000000.0)
        ->and($vue->viewData('typeBreakdown')['Vaccin'])->toBe(500000.0);
});

test('le poste des incidents est SERVI à la vue, pas codé dans le gabarit', function () {
    /*
     * La liste des postes était en dur dans le gabarit : la ligne des incidents
     * n'y aurait jamais paru, et les pourcentages — calculés sur un total qui
     * l'inclut — n'auraient plus fait 100 %.
     */
    acte($this->farm->id, $this->lot, 'Vaccin', 500_000, $this->adminUser->id);
    incident($this->farm->id, $this->lot, 2_000_000, $this->adminUser->id);

    $vue = rapportSante();

    expect($vue->viewData('costTypes'))->toContain('Incident sanitaire')
        ->and($vue->getContent())->toContain('Incident sanitaire');
});

test('le total du tableau s’accorde avec le total annoncé', function () {
    /*
     * Le gabarit recomposait le coût de chaque lot à sa façon (actes seuls),
     * tandis que le total venait du contrôleur : la colonne et le pied de
     * tableau pouvaient annoncer deux chiffres différents.
     */
    acte($this->farm->id, $this->lot, 'Traitement', 300_000, $this->adminUser->id);
    incident($this->farm->id, $this->lot, 1_200_000, $this->adminUser->id);

    $vue = rapportSante();

    $sommeDesLots = $vue->viewData('batches')->sum('sanitary_cost');

    expect($sommeDesLots)->toBe($vue->viewData('totalGlobalCost'));
});

test('sans incident, le rapport ne bouge pas', function () {
    // La borne : on ajoute une source, on ne touche pas au calcul existant.
    acte($this->farm->id, $this->lot, 'Vaccin', 500_000, $this->adminUser->id);

    $vue = rapportSante();

    expect($vue->viewData('totalGlobalCost'))->toBe(500000.0)
        ->and($vue->viewData('typeBreakdown'))->not->toHaveKey('Incident sanitaire');
});

test('le filtre de période s’applique AUSSI aux incidents', function () {
    /*
     * Les actes étaient bornés par la période demandée, pas les incidents —
     * puisqu'ils n'étaient pas chargés. Une épidémie de l'an dernier ne doit pas
     * gonfler le coût du mois.
     */
    incident($this->farm->id, $this->lot, 2_000_000, $this->adminUser->id,
        now()->subYear()->startOfYear()->toDateString());

    expect(rapportSante(['period' => 'month'])->viewData('totalGlobalCost'))->toBe(0.0);
});
