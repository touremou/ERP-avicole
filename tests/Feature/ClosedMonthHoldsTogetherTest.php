<?php

use App\Actions\MillProduction\CompleteMillProduction;
use App\Actions\Sale\ProcessSaleReturn;
use App\Models\Client;
use App\Models\CropCycle;
use App\Models\Expense;
use App\Models\Formula;
use App\Models\FormulaItem;
use App\Models\MillMachine;
use App\Models\MillProduction;
use App\Models\Plot;
use App\Models\RawMaterial;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TreasuryAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN MOIS ARRÊTÉ RESTE ARRÊTÉ — TOUTES GARDES ENSEMBLE.
 *
 * Cet audit a fermé, une par une, cinq portes par lesquelles un geste du mois
 * COURANT réécrivait le résultat d'un mois PASSÉ :
 *
 *   #256/#257  cycle de culture clôturé : réouverture par le formulaire
 *              ordinaire, puis modification/suppression de ses récoltes et
 *              intrants ;
 *   #267/#271  ordre de production : clôture d'un ordre annulé, et double
 *              clôture concurrente consommant la matière deux fois ;
 *   #269       retour de marchandise décrémentant la vente d'origine ;
 *   #270       suppression d'une dépense validée, effaçant la charge ET le
 *              mouvement de caisse.
 *
 * Chacune a son test, qui mesure SON défaut. Aucun ne vérifie qu'elles tiennent
 * ENSEMBLE — or c'est précisément la faiblesse que cet audit a rencontrée le
 * plus souvent : des règles justes prises isolément, et divergentes une fois
 * combinées.
 *
 * Ce test-ci ne cherche pas un défaut. Il fait vivre un mois complet, puis lui
 * inflige en bloc tous les gestes correctifs du mois suivant, et exige que le
 * compte de résultat du mois arrêté n'ait pas bougé d'un franc.
 *
 * Sa valeur est dans l'avenir : si une seule des cinq gardes est affaiblie un
 * jour, c'est ici que ça se verra — y compris si la régression naît d'une
 * interaction qu'aucun test isolé ne couvre.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse principale',
        'type' => 'caisse', 'current_balance' => 50_000_000, 'is_active' => true,
    ]);

    $this->debut = now()->subMonth()->startOfMonth();
    $this->fin   = now()->subMonth()->endOfMonth();
});

/** Le compte de résultat du mois arrêté : produits et charges. */
function resultatDuMoisArrete(): array
{
    $vue = test()->get(route('reports.profit_loss', [
        'date_from' => now()->subMonth()->startOfMonth()->toDateString(),
        'date_to'   => now()->subMonth()->endOfMonth()->toDateString(),
    ]))->assertOk();

    return [
        'produits' => (float) $vue->viewData('totalRevenue'),
        'charges'  => (float) $vue->viewData('totalCosts'),
    ];
}

/**
 * Le mois qu'on va arrêter : une vente, une dépense validée, un ordre de
 * production clôturé, un cycle de culture clos.
 */
function viePendantLeMois(int $farmId, int $userId): array
{
    $jour = fn (int $n) => now()->subMonth()->startOfMonth()->addDays($n)->toDateString();

    // ─── Une vente de 5 000 000 GNF ───
    $client = Client::create([
        'farm_id' => $farmId, 'client_id' => 'CLI-001',
        'name' => 'Boulangerie Centrale', 'category' => 'detaillant', 'phone' => '620000000',
    ]);

    $vente = Sale::create([
        'farm_id' => $farmId, 'client_id' => $client->id, 'reference' => 'VTE-001',
        'sale_date' => $jour(14), 'status' => 'valide',
        'total_amount' => 5_000_000, 'paid_amount' => 0, 'user_id' => $userId,
    ]);

    SaleItem::create([
        'sale_id' => $vente->id, 'product_type' => 'oeufs', 'product_name' => 'Œufs calibre L',
        'quantity' => 100, 'unit' => 'Alvéole', 'unit_price' => 50_000, 'total' => 5_000_000,
    ]);

    // ─── Une dépense validée de 2 000 000 GNF ───
    $depense = Expense::create([
        'farm_id' => $farmId, 'reference' => 'DEP-001', 'category' => 'carburant',
        'label' => 'Gasoil groupe électrogène', 'amount' => 2_000_000,
        'expense_date' => $jour(9), 'payment_method' => 'especes',
        'status' => 'valide', 'user_id' => $userId,
    ]);

    // ─── Un ordre de production clôturé (200 kg d'aliment) ───
    $matiere = RawMaterial::create([
        'farm_id' => $farmId, 'name' => 'Maïs concassé', 'unit' => 'kg',
        'stock_qty' => 1_000, 'alert_threshold' => 100, 'unit_cost' => 4_000, 'is_active' => true,
    ]);

    $formule = Formula::create([
        'farm_id' => $farmId, 'name' => 'Démarrage chair', 'code' => 'F-001',
        'target_type' => 'volaille', 'total_batch_weight' => 100, 'is_active' => true,
    ]);

    FormulaItem::create([
        'formula_id' => $formule->id, 'raw_material_id' => $matiere->id, 'percentage' => 100,
    ]);

    $machine = MillMachine::create([
        'farm_id' => $farmId, 'name' => 'Broyeur 1', 'type' => 'Broyeur',
        'capacity_per_hour' => 500, 'status' => 'Opérationnel',
    ]);

    $op = MillProduction::create([
        'farm_id' => $farmId, 'formula_id' => $formule->id, 'machine_id' => $machine->id,
        'operator_id' => $userId, 'batch_number' => 'OP-001',
        'quantity_produced' => 200, 'status' => 'Planifié',
    ]);

    app(CompleteMillProduction::class)->execute($op);

    // ─── Un cycle de culture clôturé dans le mois ───
    $parcelle = Plot::create([
        'farm_id' => $farmId, 'code' => 'P-' . Str::upper(Str::random(4)),
        'name' => 'Parcelle Nord', 'area_ha' => 3, 'status' => 'libre',
    ]);

    $cycle = CropCycle::create([
        'farm_id' => $farmId, 'plot_id' => $parcelle->id, 'code' => 'CYC-CLOS',
        'crop_name' => 'Maïs', 'planting_date' => now()->subMonths(5)->toDateString(),
        'area_used_ha' => 1, 'status' => CropCycle::STATUS_TERMINE,
        'closing_date' => $jour(24),
        'total_acquisition_cost' => 300_000, 'additional_costs' => 0,
        'total_revenue' => 900_000,
    ]);

    return compact('vente', 'depense', 'op', 'cycle', 'matiere');
}

test('le mois arrêté a bien des produits ET des charges', function () {
    /*
     * Le décor doit mordre : sans produits ni charges, tout ce qui suit
     * passerait en ne mesurant rien.
     */
    viePendantLeMois($this->farm->id, $this->adminUser->id);

    $arrete = resultatDuMoisArrete();

    expect($arrete['produits'])->toBeGreaterThan(0.0)
        ->and($arrete['charges'])->toBeGreaterThan(0.0);
});

test('aucun geste correctif du mois suivant ne déplace le résultat du mois arrêté', function () {
    /*
     * LE test. On inflige EN BLOC, aujourd'hui, tous les gestes qui réécrivaient
     * le passé avant cet audit — et on exige que le mois clos ne bouge pas.
     */
    ['vente' => $vente, 'depense' => $depense, 'op' => $op, 'cycle' => $cycle, 'matiere' => $matiere]
        = viePendantLeMois($this->farm->id, $this->adminUser->id);

    $arrete = resultatDuMoisArrete();

    /*
     * Le stock de matière première est capté LUI AUSSI. Une double clôture
     * d'ordre de production ne déplace pas le compte de résultat — la matière
     * première n'y est pas une ligne — mais elle corrompt l'inventaire, et donc
     * le coût de revient qui s'en déduira. Sans cette mesure, ce test
     * revendiquerait une couverture qu'il n'a pas : vérifié en neutralisant la
     * garde, il restait vert.
     */
    $stockMatiere = (float) $matiere->fresh()->stock_qty;

    // 1. Le client rend la moitié de la marchandise (#269).
    app(ProcessSaleReturn::class)->execute($vente, [$vente->items()->first()->id => 50], 'Casse');

    // 2. On tente de supprimer la dépense validée (#270).
    $this->delete(route('expenses.destroy', $depense));

    // 3. On tente de re-clôturer l'ordre de production (#267, #271).
    try {
        app(CompleteMillProduction::class)->execute($op->fresh());
    } catch (\Throwable) {
        // refus attendu
    }

    // 4. On tente de réécrire le cycle clôturé par le formulaire ordinaire (#256).
    $this->put(route('crop-cycles.update', $cycle), [
        'plot_id' => $cycle->plot_id, 'crop_name' => 'Maïs',
        'planting_date' => $cycle->planting_date->toDateString(),
        'area_used_ha' => 1, 'status' => CropCycle::STATUS_EN_COURS,
        'total_acquisition_cost' => 9_000_000, 'additional_costs' => 0,
    ]);

    expect(resultatDuMoisArrete())->toBe($arrete)
        ->and((float) $matiere->fresh()->stock_qty)->toBe($stockMatiere);
});

test('les gestes légitimes du mois COURANT produisent bien leur effet', function () {
    /*
     * La borne, et elle est essentielle : on protège le passé, on ne fige pas
     * le présent. Sans cette mesure, une application qui refuserait TOUT
     * ferait passer le test précédent.
     */
    ['vente' => $vente] = viePendantLeMois($this->farm->id, $this->adminUser->id);

    $avant = resultatDuMoisArrete();

    app(ProcessSaleReturn::class)->execute($vente, [$vente->items()->first()->id => 50], 'Casse');

    // Fenêtre couvrant le mois arrêté ET le mois courant : le retour y apparaît.
    $global = (float) $this->get(route('reports.profit_loss', [
        'date_from' => $this->debut->toDateString(),
        'date_to'   => now()->endOfMonth()->toDateString(),
    ]))->assertOk()->viewData('totalRevenue');

    expect($global)->toBeLessThan($avant['produits']);
});

test('la réouverture reste la sortie, et elle sort le cycle du mois arrêté', function () {
    /*
     * L'issue que cet audit a toujours préservée : on refuse la réécriture
     * silencieuse, pas la correction. Rouvrir efface la date de clôture, donc
     * le cycle quitte la période — visiblement, par un geste tracé.
     */
    ['cycle' => $cycle] = viePendantLeMois($this->farm->id, $this->adminUser->id);

    $avant = resultatDuMoisArrete();

    $this->put(route('crop-cycles.reopen', $cycle))->assertSessionHas('success');

    $apres = resultatDuMoisArrete();

    expect($apres['produits'])->toBe($avant['produits'] - 900000.0)
        ->and($cycle->fresh()->closing_date)->toBeNull();
});

test('la caisse du mois arrêté garde sa trace', function () {
    /*
     * Le second dégât de #270, celui que le contrôle de cohérence ne pouvait
     * pas voir : la suppression effaçait l'écriture ET corrigeait le solde,
     * donc sans écart détectable. L'argent est sorti ; le registre doit le dire.
     */
    ['depense' => $depense] = viePendantLeMois($this->farm->id, $this->adminUser->id);

    $ecritures = \App\Models\TreasuryTransaction::count();
    expect($ecritures)->toBeGreaterThan(0);

    $this->delete(route('expenses.destroy', $depense));

    expect(\App\Models\TreasuryTransaction::count())->toBe($ecritures);
});
