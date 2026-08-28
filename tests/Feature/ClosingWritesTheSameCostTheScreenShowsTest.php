<?php

use App\Actions\Batch\CloseBatch;
use App\Models\Batch;
use App\Models\Expense;
use App\Models\HealthIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA CLÔTURE ENREGISTRAIT UNE MARGE PLUS FLATTEUSE QUE LA RÉALITÉ.
 *
 * `CloseBatch` énumérait ses postes à la main — aliment consommé, actes du
 * registre, eau/énergie, frais annexes — là où `Batch::operating_cost`, affiché
 * sur la fiche du lot, en compte trois de plus :
 *
 *   • le traitement des INCIDENTS sanitaires (le coût d'une épidémie) ;
 *   • les DÉPENSES DIRECTES validées rattachées au lot ;
 *   • les ACHATS NON-ALIMENT (médicaments, matériel).
 *
 * Et cette marge-là est ÉCRITE dans `batches.margin`, définitivement. Une bande
 * clôturée après une épidémie traitée à 2 000 000 gardait une marge surévaluée
 * d'autant — sur le chiffre même qui sert à fixer le prix de cession de la
 * bande suivante.
 *
 * ─── LE COMMENTAIRE CROYAIT LA BOUCLE FERMÉE ───
 *
 * « L'écran de clôture affichait, lui, une estimation fondée sur la
 * consommation : le promoteur fixait son prix de vente en lisant une marge, et
 * le système en enregistrait une autre. Les deux passent désormais par la même
 * déclaration. »
 *
 * C'était une TROISIÈME déclaration, pas celle du modèle. L'écran et la clôture
 * s'accordaient entre eux, et tous deux différaient de la fiche du lot.
 *
 * ─── LA CINQUIÈME COPIE ───
 *
 * Le coût d'un lot a été écrit cinq fois dans cette base : le modèle, la
 * campagne, la clôture, l'écran de clôture, et une vue déjà nettoyée par un
 * audit antérieur. Les quatre premières lisent désormais la même.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'                => $this->farm->id,
        'building_id'            => $this->building->id,
        'arrival_date'           => today()->subDays(60)->toDateString(),
        'initial_quantity'       => 500,
        'current_quantity'       => 400,
        'status'                 => 'Actif',
        'total_acquisition_cost' => 3_000_000,
        'additional_costs'       => 0,
    ]);
});

/** Une épidémie traitée, rattachée au lot. */
function epidemieTraitee(int $farmId, int $buildingId, Batch $lot, float $cout, int $userId): HealthIncident
{
    return HealthIncident::create([
        'farm_id'         => $farmId,
        'batch_id'        => $lot->id,
        'building_id'     => $buildingId,
        'user_id'         => $userId,
        'incident_date'   => today()->subDays(10)->toDateString(),
        'mortality_count' => 0,
        'symptoms'        => 'Diarrhée, abattement',
        'treatment_cost'  => $cout,
        'status'          => 'resolu',
    ]);
}

/** Une dépense directe validée du lot. */
function depenseValidee(int $farmId, Batch $lot, float $montant, int $userId, string $statut = 'valide'): Expense
{
    return Expense::create([
        'farm_id'      => $farmId,
        'batch_id'     => $lot->id,
        'user_id'      => $userId,
        'reference'    => 'DEP-' . uniqid(),
        'category'     => 'transport',
        'label'        => 'Transport',
        'amount'       => $montant,
        'expense_date' => today()->subDays(5)->toDateString(),
        'status'       => $statut,
    ]);
}

test('la marge ENREGISTRÉE compte le coût des épidémies', function () {
    /*
     * LE défaut, sur un chiffre définitif. 400 sujets à 10 000 = 4 000 000 de
     * revenu ; acquisition 3 000 000 ; épidémie 2 000 000. La marge est donc
     * NÉGATIVE de 1 000 000 — et non positive de 1 000 000 comme l'enregistrait
     * la clôture.
     */
    epidemieTraitee($this->farm->id, $this->building->id, $this->lot, 2_000_000, $this->adminUser->id);

    app(CloseBatch::class)->execute($this->lot, [
        'closing_date'               => today()->toDateString(),
        'actual_sell_price_per_unit' => 10_000,
    ]);

    expect((float) $this->lot->fresh()->margin)->toBe(-1_000_000.0);
});

test('elle compte aussi les DÉPENSES DIRECTES validées', function () {
    depenseValidee($this->farm->id, $this->lot, 500_000, $this->adminUser->id);

    app(CloseBatch::class)->execute($this->lot, [
        'closing_date'               => today()->toDateString(),
        'actual_sell_price_per_unit' => 10_000,
    ]);

    // 4 000 000 − 3 000 000 − 500 000
    expect((float) $this->lot->fresh()->margin)->toBe(500_000.0);
});

test('une dépense NON validée ne pèse pas — la borne', function () {
    depenseValidee($this->farm->id, $this->lot, 500_000, $this->adminUser->id, 'en_attente');

    app(CloseBatch::class)->execute($this->lot, [
        'closing_date'               => today()->toDateString(),
        'actual_sell_price_per_unit' => 10_000,
    ]);

    expect((float) $this->lot->fresh()->margin)->toBe(1_000_000.0);
});

test('les FRAIS ANNEXES saisis à la clôture sont pris dans leur version saisie', function () {
    /*
     * `additional_costs` est modifiable dans le formulaire, et `operating_cost`
     * lit la COLONNE. Sans l'écriture préalable, la marge enregistrée retiendrait
     * l'ancienne valeur — celle que le promoteur vient justement de corriger.
     */
    app(CloseBatch::class)->execute($this->lot, [
        'closing_date'               => today()->toDateString(),
        'actual_sell_price_per_unit' => 10_000,
        'additional_costs'           => 750_000,
    ]);

    $lot = $this->lot->fresh();

    expect((float) $lot->additional_costs)->toBe(750_000.0)
        ->and((float) $lot->margin)->toBe(250_000.0);   // 4 000 000 − 3 000 000 − 750 000
});

test('la marge enregistrée ÉGALE celle que la fiche du lot affiche', function () {
    /*
     * L'invariant que la déclaration unique installe : le chiffre écrit en base
     * à la clôture et celui que le modèle recalcule doivent coïncider.
     */
    epidemieTraitee($this->farm->id, $this->building->id, $this->lot, 300_000, $this->adminUser->id);
    depenseValidee($this->farm->id, $this->lot, 200_000, $this->adminUser->id);

    app(CloseBatch::class)->execute($this->lot, [
        'closing_date'               => today()->toDateString(),
        'actual_sell_price_per_unit' => 10_000,
        'additional_costs'           => 100_000,
    ]);

    $lot = $this->lot->fresh();

    expect((float) $lot->margin)->toBe((float) $lot->net_margin);
});

test('l’ÉCRAN de clôture annonce le total que la clôture va enregistrer', function () {
    /*
     * Le couple qui comptait : le promoteur fixe son prix en lisant l'écran. Le
     * total affiché plus les frais annexes saisis doit valoir, au franc près, ce
     * que la clôture retiendra.
     *
     * Les frais annexes sont EXCLUS de `total_known` : la vue les tient dans un
     * champ modifiable et fait « total = coûts connus + frais saisis ». Les y
     * laisser les compterait deux fois.
     */
    epidemieTraitee($this->farm->id, $this->building->id, $this->lot, 800_000, $this->adminUser->id);
    depenseValidee($this->farm->id, $this->lot, 150_000, $this->adminUser->id);
    $this->lot->forceFill(['additional_costs' => 90_000])->save();

    $reponse = $this->get(route('batches.close_form', $this->lot))->assertOk();
    $costs   = $reponse->viewData('costs');

    $totalAffiche = (float) $costs['total_known'] + 90_000;

    app(CloseBatch::class)->execute($this->lot, [
        'closing_date'               => today()->toDateString(),
        'actual_sell_price_per_unit' => 10_000,
        'additional_costs'           => 90_000,
    ]);

    $lot = $this->lot->fresh();

    // Charges enregistrées = revenu − marge.
    expect(4_000_000.0 - (float) $lot->margin)->toBe($totalAffiche);
});
