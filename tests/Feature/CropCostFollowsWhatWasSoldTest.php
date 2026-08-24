<?php

use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Harvest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE COMPTE DE RÉSULTAT IMPUTAIT LE COÛT DE CE QUI N'ÉTAIT PAS ENCORE VENDU.
 *
 * Le cycle de culture porte la bonne déclaration depuis longtemps :
 * `CropCycle::costOfGoodsSold()` retire du coût engagé la valorisation des
 * récoltes CONSERVÉES — séchage, transformation, vente différée — parce que leur
 * coût suit la matière dans l'inventaire. Le commentaire du modèle décrit
 * précisément le piège :
 *
 *     « L'imputer au cycle afficherait une marge catastrophique le mois de la
 *       récolte, puis un profit sans coût le mois de la vente — deux mensonges
 *       qui se compensent, et aucun pilotage possible. »
 *
 * Le compte de résultat ne l'avait jamais suivie. À DEUX endroits — le coût
 * global des cultures et la marge par culture — il recomposait
 * « acquisition + forfaits + intrants », c'est-à-dire exactement
 * `CropCycle::total_cost`, le coût ENGAGÉ.
 *
 * En face, le revenu ne compte que les récoltes VENDUES (`total_revenue` est
 * recalculé depuis les récoltes `sold()`). Un cycle dont une partie sèche ou
 * attend un meilleur prix se voyait donc imputer le coût de ce qui est encore en
 * inventaire, sans la recette correspondante.
 *
 * Trois implémentations de la même question, dont deux naïves.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Un cycle clos, avec son coût engagé et ses récoltes. */
function cycleClos(int $farmId, float $acquisition, float $forfaits, float $intrants): CropCycle
{
    $parcelle = \App\Models\Plot::create([
        'farm_id' => $farmId,
        'name'    => 'Parcelle ' . \Illuminate\Support\Str::random(4),
    ]);

    $cycle = CropCycle::create([
        'farm_id'                => $farmId,
        'plot_id'                => $parcelle->id,
        'crop_name'              => 'Maïs',
        'planting_date'          => today()->subDays(90)->toDateString(),
        'closing_date'           => today()->toDateString(),
        'status'                 => 'termine',
        'total_acquisition_cost' => $acquisition,
        'additional_costs'       => $forfaits,
    ]);

    if ($intrants > 0) {
        CropInput::create([
            'farm_id'       => $farmId,
            'crop_cycle_id' => $cycle->id,
            'input_date'    => today()->subDays(10)->toDateString(),
            'type'          => 'engrais',
            'name'          => 'NPK',
            'quantity'      => 1,
            'unit'          => 'sac',
            'total_cost'    => $intrants,
        ]);
    }

    return $cycle->fresh();
}

/** Une récolte du cycle, vendue ou conservée. */
function recolteDestinee(CropCycle $cycle, float $kg, string $destination, float $prixKg = 0): Harvest
{
    return Harvest::create([
        'farm_id'       => $cycle->farm_id,
        'crop_cycle_id' => $cycle->id,
        'harvest_date'  => today()->subDays(5)->toDateString(),
        'quantity'      => $kg,
        'unit'          => 'kg',
        'net_weight_kg' => $kg,
        'destination'   => $destination,
        'unit_price'    => $prixKg,
    ]);
}

test('une récolte CONSERVÉE ne charge pas le cycle', function () {
    /*
     * La déclaration du modèle, vérifiée d'abord seule. 1 000 000 de coût pour
     * 1 000 kg récoltés dont 400 conservés : le coût des marchandises vendues
     * vaut 600 000, pas 1 000 000.
     */
    $cycle = cycleClos($this->farm->id, 600_000, 200_000, 200_000);

    recolteDestinee($cycle, 600, Harvest::DEST_VENTE, 2_000);
    recolteDestinee($cycle, 400, Harvest::DEST_STOCKAGE);

    expect($cycle->fresh()->costOfGoodsSold())->toBe(600_000.0);
});

test('le COMPTE DE RÉSULTAT suit la même règle', function () {
    /*
     * LE défaut. Il imputait le coût entier — 1 000 000 — alors que la recette
     * en face ne porte que sur les 600 kg vendus.
     */
    $cycle = cycleClos($this->farm->id, 600_000, 200_000, 200_000);

    recolteDestinee($cycle, 600, Harvest::DEST_VENTE, 2_000);
    recolteDestinee($cycle, 400, Harvest::DEST_STOCKAGE);

    $couts = $this->get(route('reports.profit_loss'))->assertOk()->viewData('costs');

    expect((float) $couts['Production végétale (cultures)'])->toBe(600_000.0);
});

test('la MARGE PAR CULTURE aussi', function () {
    // Le second lecteur naïf, dans le même rapport.
    $cycle = cycleClos($this->farm->id, 600_000, 200_000, 200_000);

    recolteDestinee($cycle, 600, Harvest::DEST_VENTE, 2_000);
    recolteDestinee($cycle, 400, Harvest::DEST_STOCKAGE);

    $marges = $this->get(route('reports.profit_loss'))->viewData('cropMargin');
    $ligne  = collect($marges)->firstWhere('crop', $cycle->fresh()->crop_name);

    expect((float) $ligne['cost'])->toBe(600_000.0);
});

test('un cycle SANS récolte conservée ne bouge pas d’un franc', function () {
    /*
     * La borne de non-régression : tout vendu, la valorisation conservée vaut 0
     * et la formule redevient exactement l'ancienne.
     */
    $cycle = cycleClos($this->farm->id, 600_000, 200_000, 200_000);

    recolteDestinee($cycle, 1_000, Harvest::DEST_VENTE, 2_000);

    $couts = $this->get(route('reports.profit_loss'))->viewData('costs');

    expect((float) $couts['Production végétale (cultures)'])->toBe(1_000_000.0)
        ->and($cycle->fresh()->costOfGoodsSold())->toBe(1_000_000.0);
});

test('la marge du CYCLE et celle du RAPPORT concordent', function () {
    /*
     * L'invariant qui motivait la correction : deux écrans, une seule réponse.
     */
    $cycle = cycleClos($this->farm->id, 600_000, 200_000, 200_000);

    recolteDestinee($cycle, 600, Harvest::DEST_VENTE, 2_000);
    recolteDestinee($cycle, 400, Harvest::DEST_TRANSFORMATION);

    $marges = $this->get(route('reports.profit_loss'))->viewData('cropMargin');
    $ligne  = collect($marges)->firstWhere('crop', $cycle->fresh()->crop_name);

    expect((float) $ligne['margin'])->toBe((float) $cycle->fresh()->net_margin);
});

test('le rapport ne recompose plus le coût chez lui', function () {
    /*
     * La garde contre le retour de la divergence : une seconde formule
     * divergerait au premier ajustement du modèle.
     */
    $source = file_get_contents(base_path('app/Http/Controllers/ReportController.php'));

    expect(str_contains($source, 'costOfGoodsSold()'))->toBeTrue()
        ->and(str_contains($source, "DB::raw('total_acquisition_cost + additional_costs')"))
        ->toBeFalse('Le coût des cultures doit venir du modèle, pas d’une somme recomposée.');
});
