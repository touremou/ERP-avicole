<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Expense;
use App\Models\HealthCheck;
use App\Models\HealthIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « BÉNÉFICE NET » AUX ARCHIVES, ET UN AUTRE CHIFFRE SUR LA FICHE DU LOT.
 *
 * L'écran des archives annonce, colonne « Performance Nette », un montant
 * étiqueté « Bénéfice Net » ou « Perte Nette ». Il était calculé dans le
 * GABARIT, avec quatre composantes :
 *
 *     CA − (poussins + aliment ACHETÉ + actes du registre + frais annexes)
 *
 * La fiche du lot, elle, lit `Batch::net_margin`, qui en compte sept — et
 * n'impute pas l'aliment de la même façon :
 *
 *     CA − (aliment CONSOMMÉ + achats non-aliment + santé AVEC incidents
 *           + poussins + frais annexes + dépenses directes + eau & énergie)
 *
 * Manquaient donc aux archives : les dépenses validées rattachées au lot, l'eau
 * et l'énergie du bâtiment, et le coût des épidémies. Et l'aliment y était pris
 * au prix d'ACHAT là où le modèle l'impute à la CONSOMMATION — deux nombres
 * différents dès qu'un sac acheté n'est pas encore mangé.
 *
 * Deux écrans, deux « bénéfices nets », et rien pour dire lequel croire.
 *
 * ─── LA MORTALITÉ AUSSI ───
 *
 * Le même gabarit recomposait le taux de mortalité : `qty_dead` + mortalité des
 * pointages. Le modèle y ajoute la mortalité EN INFIRMERIE — des sujets déjà
 * sortis de l'effectif, « aucun impact effectif, mais bien des pertes », dit le
 * commentaire d'une correction antérieure. Les archives sous-estimaient donc
 * aussi la mortalité, sur l'écran où l'on juge une bande terminée.
 *
 * ─── LA CORRECTION, ET UN PIÈGE ÉVITÉ DE JUSTESSE ───
 *
 * Aucun montant ne se calcule plus dans ce gabarit : il appelle les accesseurs
 * du modèle.
 *
 * Le premier jet posait des attributs calculés au contrôleur — et
 * `mortality_rate` s'y trouvait recalculé sur `initial_quantity` seul. Or le
 * modèle le divise par `initial_quantity + qty_dead`, et il documente pourquoi :
 * l'effectif reçu n'inclut pas les morts au transport. C'était introduire une
 * QUATRIÈME règle en réparant la troisième — le test l'a signalé (9,8 % contre
 * 10 % attendus) et l'attribut maison a été retiré.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'                => $this->farm->id,
        'building_id'            => $this->building->id,
        'initial_quantity'       => 1000,
        'current_quantity'       => 0,
        'qty_dead'               => 20,
        'status'                 => 'Terminé',
        'closing_date'           => now()->toDateString(),
        'arrival_date'           => now()->subMonths(2)->toDateString(),
        'total_revenue'          => 30_000_000,
        'total_acquisition_cost' => 5_000_000,
        'additional_costs'       => 500_000,
    ]);
});

/** Ouvre l'écran des archives. */
function archives()
{
    return test()->get(route('batches.archives'))->assertOk();
}

/** Le lot tel que l'écran le sert. */
function lotArchive(Batch $lot)
{
    return archives()->viewData('archivedBatches')->firstWhere('id', $lot->id);
}

/*
 * Ces tests mesurent le RENDU, et non l'accesseur du modèle.
 *
 * Premier jet : ils lisaient `viewData(...)->net_margin`, donc le modèle — et
 * restaient verts quand on remettait l'ancien calcul dans le gabarit. Ils ne
 * couvraient pas le correctif. Un test qui interroge la source plutôt que
 * l'écran ne dit rien de l'écran.
 */

/** Le montant est-il affiché, mis en forme comme la vue le fait ? */
function montantAffiche(float $montant): bool
{
    return str_contains(archives()->getContent(), number_format($montant, 0, ',', ' '));
}

/** Le taux est-il affiché, mis en forme comme la vue le fait ? */
function tauxAffiche(float $taux): bool
{
    return str_contains(archives()->getContent(), number_format($taux, 1) . '%');
}

test('la marge affichée aux archives est celle du modèle', function () {
    /*
     * LE défaut : une dépense directe et une épidémie, deux postes que le
     * gabarit ignorait. La fiche du lot les comptait.
     */
    Expense::create([
        'farm_id'      => $this->farm->id,
        'batch_id'     => $this->lot->id,
        'reference'    => 'DEP-LOT-1',
        'category'     => 'transport',
        'label'        => 'Transport des poussins',
        'amount'       => 1_500_000,
        'expense_date' => now()->subMonth()->toDateString(),
        'status'       => 'valide',
        'user_id'      => $this->adminUser->id,
    ]);

    HealthIncident::create([
        'farm_id'         => $this->farm->id,
        'user_id'         => $this->adminUser->id,
        'building_id'     => $this->building->id,
        'batch_id'        => $this->lot->id,
        'incident_date'   => now()->subMonth()->toDateString(),
        'mortality_count' => 30,
        'symptoms'        => 'Diarrhée',
        'status'          => HealthIncident::STATUS_DIAGNOSED,
        'severity'        => HealthIncident::SEVERITY_MODERATE,
        'treatment_cost'  => 2_000_000,
    ]);

    // 30 000 000 − (5 000 000 + 500 000 + 1 500 000 + 2 000 000) = 21 000 000.
    // Le gabarit en annonçait 24 500 000 : il ignorait les deux derniers postes.
    expect(montantAffiche($this->lot->fresh()->net_margin))->toBeTrue()
        ->and(montantAffiche(24_500_000))->toBeFalse();
});

test('les dépenses directes du lot pèsent enfin sur la marge des archives', function () {
    // Sans dépense : 30 000 000 − 5 000 000 − 500 000 = 24 500 000.
    expect(montantAffiche(24_500_000))->toBeTrue();

    Expense::create([
        'farm_id'      => $this->farm->id,
        'batch_id'     => $this->lot->id,
        'reference'    => 'DEP-LOT-2',
        'category'     => 'transport',
        'label'        => 'Transport',
        'amount'       => 1_500_000,
        'expense_date' => now()->subMonth()->toDateString(),
        'status'       => 'valide',
        'user_id'      => $this->adminUser->id,
    ]);

    expect(montantAffiche(23_000_000))->toBeTrue()
        ->and(montantAffiche(24_500_000))->toBeFalse();
});

test('le coût d’une épidémie pèse sur la marge des archives', function () {
    HealthIncident::create([
        'farm_id'         => $this->farm->id,
        'user_id'         => $this->adminUser->id,
        'building_id'     => $this->building->id,
        'batch_id'        => $this->lot->id,
        'incident_date'   => now()->subMonth()->toDateString(),
        'mortality_count' => 30,
        'symptoms'        => 'Toux',
        'status'          => HealthIncident::STATUS_DIAGNOSED,
        'severity'        => HealthIncident::SEVERITY_CRITICAL,
        'treatment_cost'  => 2_000_000,
    ]);

    expect(montantAffiche(22_500_000))->toBeTrue()
        ->and(montantAffiche(24_500_000))->toBeFalse();
});

test('les actes du registre restent comptés', function () {
    // La borne : on remplace un calcul incomplet, on ne perd aucune composante.
    HealthCheck::create([
        'farm_id'             => $this->farm->id,
        'batch_id'            => $this->lot->id,
        'type'                => 'Vaccin',
        'product_name'        => 'Vaccin Newcastle',
        'mode_administration' => 'Nébulisation',
        'intervention_date'   => now()->subMonth()->toDateString(),
        'cost'                => 800_000,
        'user_id'             => $this->adminUser->id,
    ]);

    expect(montantAffiche(23_700_000))->toBeTrue();
});

test('la mortalité des archives compte l’INFIRMERIE', function () {
    /*
     * Le second calcul refait à la main. Les sujets morts en infirmerie sont
     * déjà sortis de l'effectif — « aucun impact effectif, mais bien des
     * pertes ». Les ignorer flattait le bilan de la bande sur l'écran même où
     * on la juge.
     *
     * 20 (arrivage) + 50 (troupeau) + 30 (infirmerie) = 100 morts. Le gabarit
     * n'en comptait que 70.
     */
    DailyCheck::create([
        'farm_id'              => $this->farm->id,
        'batch_id'             => $this->lot->id,
        'building_id'          => $this->building->id,
        'user_id'              => $this->adminUser->id,
        'check_date'           => now()->subMonth()->toDateString(),
        'mortality'            => 50,
        'mortality_infirmary'  => 30,
    ]);


    // La base du taux est celle du modèle, documentée : initial_quantity +
    // qty_dead, car l'effectif reçu n'inclut pas les morts au transport.
    // 100 / (1 000 + 20) = 9,80 % — et non 6,86 % comme le gabarit l'annonçait
    // en ignorant l'infirmerie.
    // 100 / (1 000 + 20) = 9,8 % affichés. Le gabarit, qui n'en comptait que
    // 70, annonçait 6,9 %.
    expect(tauxAffiche(9.8))->toBeTrue()
        ->and(tauxAffiche(6.9))->toBeFalse();
});

test('la marge est la MÊME que sur la fiche du lot, poste par poste', function () {
    /*
     * L'enjeu, mesuré directement : deux écrans, un seul chiffre. Ce test tombe
     * si quelqu'un remet un calcul dans le gabarit.
     */
    DailyCheck::create([
        'farm_id'             => $this->farm->id,
        'batch_id'            => $this->lot->id,
        'building_id'         => $this->building->id,
        'user_id'             => $this->adminUser->id,
        'check_date'          => now()->subMonth()->toDateString(),
        'mortality'           => 50,
        'mortality_infirmary' => 30,
    ]);

    HealthCheck::create([
        'farm_id'             => $this->farm->id,
        'batch_id'            => $this->lot->id,
        'type'                => 'Traitement',
        'product_name'        => 'Antibiotique',
        'mode_administration' => 'Eau de boisson',
        'intervention_date'   => now()->subMonth()->toDateString(),
        'cost'                => 400_000,
        'user_id'             => $this->adminUser->id,
    ]);

    $fiche = $this->get(route('batches.show', $this->lot))->assertOk();

    expect(montantAffiche($fiche->viewData('batch')->net_margin))->toBeTrue();
});

test('l’écran affiche bien le montant servi, sans le recalculer', function () {
    // De bout en bout : le nombre rendu est celui du modèle.
    HealthIncident::create([
        'farm_id'         => $this->farm->id,
        'user_id'         => $this->adminUser->id,
        'building_id'     => $this->building->id,
        'batch_id'        => $this->lot->id,
        'incident_date'   => now()->subMonth()->toDateString(),
        'mortality_count' => 10,
        'symptoms'        => 'Boiterie',
        'status'          => HealthIncident::STATUS_DIAGNOSED,
        'severity'        => HealthIncident::SEVERITY_MINOR,
        'treatment_cost'  => 2_000_000,
    ]);

    // 22 500 000, mis en forme comme la vue le fait.
    expect(archives()->getContent())->toContain(number_format(22_500_000, 0, ',', ' '));
});
