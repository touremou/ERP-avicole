<?php

use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Accounting\PeriodRevenue;
use App\Services\DashboardInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CORRIGER DEUX LECTEURS SUR QUATRE NE REFERME PAS UNE DIVERGENCE — ELLE LA DÉPLACE.
 *
 * #310 a établi la règle : le chiffre d'affaires est net des remises et EXCLUT
 * la taxe collectée. Deux lecteurs ont été alignés — le compte de résultat et le
 * tableau de bord.
 *
 * Deux autres ne l'étaient pas, et se sont donc retrouvés seuls à annoncer un
 * chiffre gonflé :
 *
 *   • le HUB COMMERCE, dont la tuile « CA du jour » sommait `total_amount` ;
 *   • le RÉSUMÉ QUOTIDIEN envoyé au promoteur, dont la ligne « CA : » faisait de
 *     même. C'est le message lu chaque matin, souvent depuis l'étranger.
 *
 * ─── CE QUI RESTE DÉLIBÉRÉMENT EN TTC ───
 *
 * Tout ce qui répond à « combien nous doit-on » ou « combien est entré » :
 * créances clients, dette fournisseur, encaissements, total du tiroir-caisse,
 * relevé client. Un client doit bien le TTC, taxe et livraison comprises. Ce sont
 * d'autres questions, et elles n'ont pas la même réponse — les confondre serait
 * l'erreur symétrique.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-' . Str::random(6),
        'name' => 'Grossiste', 'type' => 'entreprise', 'category' => 'grossiste', 'status' => 'actif',
    ]);

    // Une facture du jour : 1 000 000 de marchandise, remise 10 %, TVA 18 %.
    // Recette attendue partout : 900 000. TTC facturé : 1 062 000.
    $this->vente = Sale::create([
        'farm_id' => $this->farm->id, 'uuid' => (string) Str::uuid(),
        'reference' => 'FA-' . Str::random(6), 'client_id' => $client->id,
        'sale_date' => today()->toDateString(), 'type' => 'facture_tva',
        'status' => 'valide', 'tax_rate' => 18,
        'discount_type' => 'percent', 'discount_value' => 10,
        'user_id' => $this->adminUser->id,
    ]);

    SaleItem::create([
        'sale_id' => $this->vente->id, 'product_type' => 'oeufs', 'product_name' => 'Alvéole',
        'quantity' => 1, 'unit_price' => 1_000_000, 'total' => 1_000_000,
    ]);

    $this->vente->recalculateTotals();
    $this->vente->refresh();

    $this->attendu = 900_000.0;
});

test('la DÉCLARATION dit 900 000, pas le TTC facturé', function () {
    // Le point de référence : la remise est déduite, la taxe reste dehors.
    expect(array_sum(PeriodRevenue::byProductType(today()->startOfDay(), today()->endOfDay())))
        ->toBe($this->attendu)
        ->and((float) $this->vente->total_amount)->toBe(1_062_000.0);
});

test('le HUB COMMERCE annonce le même chiffre', function () {
    /*
     * Il sommait `total_amount` : 1 062 000 au lieu de 900 000, soit la TVA
     * comptée comme recette et la remise ignorée.
     */
    $kpis = $this->get(route('commerce.index'))->assertOk()->viewData('kpis');

    expect((float) $kpis['ca_jour'])->toBe($this->attendu);
});

test('le TABLEAU DE BORD aussi', function () {
    $financier = app(DashboardInsightsService::class)
        ->financial(today()->startOfDay(), today()->endOfDay());

    expect((float) $financier['ca_ventes'])->toBe($this->attendu);
});

test('le COMPTE DE RÉSULTAT aussi', function () {
    $recettes = $this->get(route('reports.profit_loss'))->assertOk()->viewData('totalRevenue');

    expect((float) $recettes)->toBe($this->attendu);
});

test('les CRÉANCES restent en TTC — et c’est voulu', function () {
    /*
     * L'erreur symétrique, qu'il ne faut pas commettre en « alignant » tout.
     * Un client doit le TTC : taxe et livraison comprises. Ce que la ferme a
     * gagné et ce que le client doit sont deux questions différentes.
     */
    $kpis = $this->get(route('commerce.index'))->viewData('kpis');

    expect((float) $kpis['creances'])->toBe(1_062_000.0);
});

test('aucun écran ne recompose le CA chez lui', function () {
    /*
     * La garde qui empêche le retour de la divergence. Elle vise les quatre
     * lecteurs connus : si un cinquième apparaît un jour, c'est cette liste
     * qu'il faudra étendre — pas une formule à recopier.
     */
    $lecteurs = [
        'app/Http/Controllers/CommerceController.php',
        'app/Services/DashboardInsightsService.php',
        'app/Http/Controllers/ReportController.php',
        'app/Services/NotificationHub.php',
    ];

    foreach ($lecteurs as $fichier) {
        $code = file_get_contents(base_path($fichier));

        expect(str_contains($code, 'PeriodRevenue::byProductType'))
            ->toBeTrue("{$fichier} doit lire la déclaration commune du chiffre d’affaires.");
    }
});
