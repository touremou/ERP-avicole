<?php

use App\Models\Batch;
use App\Models\Campaign;
use App\Models\Expense;
use App\Models\HealthIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE COÛT D'UNE CAMPAGNE NE DISAIT PAS CE QUE DISAIT LA MARGE DE SES LOTS.
 *
 * `Campaign::operating_cost` recopiait le calcul de `Batch::net_margin`, et la
 * copie avait divergé. Elle sommait les ACHATS d'aliment (tous confondus) et les
 * seuls actes du registre sanitaire, en laissant de côté :
 *
 *   • le traitement des INCIDENTS sanitaires — dont le commentaire de la marge
 *     dit qu'il « ferme la boucle financière incident → marge » ;
 *   • les DÉPENSES DIRECTES validées rattachées au lot ;
 *
 * et en imputant l'aliment ACHETÉ plutôt que CONSOMMÉ, ce que la marge avait
 * précisément cessé de faire (« l'aliment acheté mais non encore consommé reste
 * un actif de stock plutôt qu'une charge du lot »).
 *
 * ─── CE N'EST PAS LA PREMIÈRE COPIE ───
 *
 * `BatchController` porte le récit d'une TROISIÈME implémentation, retirée
 * d'une vue lors d'un audit antérieur : « CA − (poussins + aliment ACHETÉ +
 * actes du registre + frais annexes). Quatre composantes, quand
 * `Batch::net_margin` en compte sept […] manquaient les dépenses directes du
 * lot, l'eau et l'énergie du bâtiment, et le coût des épidémies. »
 *
 * Ce sont EXACTEMENT les postes que la campagne omettait encore. La correction
 * avait traité la vue, pas l'échelon intermédiaire.
 *
 * ─── L'ACQUISITION RESTE DEHORS ───
 *
 * La campagne la présente sur sa propre ligne. `Batch::operating_cost` ne la
 * contient donc pas, sans quoi `total_cost` la compterait deux fois.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->campagne = Campaign::create([
        'farm_id'    => $this->farm->id,
        'name'       => 'Campagne Tabaski',
        'type'       => 'engraissement',
        'status'     => 'en_cours',
        'start_date'  => today()->subDays(60)->toDateString(),
        'target_date' => today()->addDays(30)->toDateString(),
    ]);

    $this->lot = Batch::factory()->create([
        'farm_id'                => $this->farm->id,
        'building_id'            => $this->building->id,
        'campaign_id'            => $this->campagne->id,
        'arrival_date'           => today()->subDays(60)->toDateString(),
        'initial_quantity'       => 500,
        'current_quantity'       => 500,
        'status'                 => 'Actif',
        'total_acquisition_cost' => 3_000_000,
        'additional_costs'       => 0,
    ]);
});

test('le coût de campagne inclut le traitement des INCIDENTS sanitaires', function () {
    /*
     * LE défaut, sur le poste le plus lourd d'un accident d'élevage. Une
     * épidémie traitée à 2 000 000 amputait la marge du lot d'autant et le coût
     * de la campagne de zéro.
     */
    HealthIncident::create([
        'farm_id'        => $this->farm->id,
        'batch_id'       => $this->lot->id,
        'building_id'    => $this->building->id,
        'user_id'        => $this->adminUser->id,
        'incident_date'  => today()->subDays(10)->toDateString(),
        'mortality_count' => 0,
        'symptoms'        => 'Diarrhée, abattement',
        'treatment_cost' => 2_000_000,
        'status'         => 'resolu',
    ]);

    expect((float) $this->campagne->fresh()->operating_cost)->toBe(2_000_000.0);
});

test('il inclut les DÉPENSES DIRECTES validées du lot', function () {
    Expense::create([
        'farm_id'      => $this->farm->id,
        'batch_id'     => $this->lot->id,
        'user_id'      => $this->adminUser->id,
        'reference'    => 'DEP-TEST-1',
        'category'     => 'transport',
        'label'        => 'Transport des sujets',
        'amount'       => 450_000,
        'expense_date' => today()->subDays(5)->toDateString(),
        'status'       => 'valide',
    ]);

    expect((float) $this->campagne->fresh()->operating_cost)->toBe(450_000.0);
});

test('une dépense NON validée ne pèse pas — la borne', function () {
    /*
     * La marge ne retient que les dépenses validées ; la campagne doit s'aligner
     * dans les DEUX sens, sinon on remplace une omission par une surestimation.
     */
    Expense::create([
        'farm_id'      => $this->farm->id,
        'batch_id'     => $this->lot->id,
        'user_id'      => $this->adminUser->id,
        'reference'    => 'DEP-TEST-2',
        'category'     => 'transport',
        'label'        => 'En attente de validation',
        'amount'       => 900_000,
        'expense_date' => today()->subDays(5)->toDateString(),
        'status'       => 'en_attente',
    ]);

    expect((float) $this->campagne->fresh()->operating_cost)->toBe(0.0);
});

test('campagne et marge du lot lisent la MÊME déclaration', function () {
    /*
     * L'invariant que la déclaration unique installe : quels que soient les
     * postes, le coût d'exploitation annoncé par la campagne est exactement la
     * somme de celui de ses lots.
     */
    HealthIncident::create([
        'farm_id'        => $this->farm->id,
        'batch_id'       => $this->lot->id,
        'building_id'    => $this->building->id,
        'user_id'        => $this->adminUser->id,
        'incident_date'  => today()->subDays(10)->toDateString(),
        'mortality_count' => 0,
        'symptoms'        => 'Diarrhée, abattement',
        'treatment_cost' => 750_000,
        'status'         => 'resolu',
    ]);

    Expense::create([
        'farm_id'      => $this->farm->id,
        'batch_id'     => $this->lot->id,
        'user_id'      => $this->adminUser->id,
        'reference'    => 'DEP-TEST-3',
        'category'     => 'transport',
        'label'        => 'Transport',
        'amount'       => 250_000,
        'expense_date' => today()->subDays(5)->toDateString(),
        'status'       => 'valide',
    ]);

    expect((float) $this->campagne->fresh()->operating_cost)
        ->toBe((float) $this->lot->fresh()->operating_cost);
});

test('l’ACQUISITION n’est comptée qu’une fois dans le coût total', function () {
    /*
     * La borne qui protège l'extraction : `total_cost` vaut acquisition +
     * exploitation. Si l'exploitation portait aussi l'acquisition, les 3 000 000
     * du lot compteraient double.
     */
    $campagne = $this->campagne->fresh();

    expect((float) $campagne->acquisition_cost)->toBe(3_000_000.0)
        ->and((float) $campagne->operating_cost)->toBe(0.0)
        ->and((float) $campagne->total_cost)->toBe(3_000_000.0);
});

test('la MARGE du lot est inchangée par l’extraction — non-régression', function () {
    /*
     * Extraire le coût dans sa propre déclaration ne doit pas bouger le chiffre
     * que les écrans affichent déjà.
     */
    HealthIncident::create([
        'farm_id'        => $this->farm->id,
        'batch_id'       => $this->lot->id,
        'building_id'    => $this->building->id,
        'user_id'        => $this->adminUser->id,
        'incident_date'  => today()->subDays(10)->toDateString(),
        'mortality_count' => 0,
        'symptoms'        => 'Diarrhée, abattement',
        'treatment_cost' => 500_000,
        'status'         => 'resolu',
    ]);

    $lot = $this->lot->fresh();

    // revenu (0) − exploitation (500 000) − acquisition (3 000 000)
    expect((float) $lot->net_margin)->toBe(-3_500_000.0);
});
