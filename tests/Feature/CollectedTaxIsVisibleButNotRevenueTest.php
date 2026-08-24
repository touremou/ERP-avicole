<?php

use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Accounting\PeriodRevenue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA TVA COLLECTÉE ÉTAIT ÉCRITE SUR CHAQUE FACTURE ET TOTALISÉE NULLE PART.
 *
 * `sales.tax_amount` est écrit par `recalculateTotals()` depuis toujours. Il
 * n'était lu que sur la facture individuelle — l'écran de vente et son
 * impression. Aucun état, aucun rapport, aucun tableau ne totalisait jamais ce
 * que l'exploitation avait collecté sur une période.
 *
 * Tant que la TVA était (à tort) comptée dans le chiffre d'affaires, elle était
 * au moins quelque part. #310 l'en a sortie — à juste titre, cet argent
 * appartient à l'État — et l'a du même coup rendue INVISIBLE : encaissée en
 * caisse, absente des recettes, absente de tout état.
 *
 * ─── CE CHIFFRE N'EST PAS LA TVA DUE, ET L'ÉCRAN LE DIT ───
 *
 * La TVA à reverser vaut « collectée − déductible ». Ni `supplier_invoices` ni
 * `expenses` ne portent le moindre champ de taxe : l'application n'enregistre
 * pas la TVA payée sur les achats, donc elle ne peut pas calculer le net.
 *
 * On expose la seule moitié que la base connaît, en la nommant pour ce qu'elle
 * est. Afficher « TVA à payer » sur une moitié de l'équation serait pire que de
 * ne rien afficher : cela fonderait une déclaration sur un chiffre toujours trop
 * élevé.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-' . Str::random(6),
        'name' => 'Grossiste', 'type' => 'entreprise', 'category' => 'grossiste', 'status' => 'actif',
    ]);
});

/** Une facture validée, taxée au taux voulu. */
function factureTaxee(int $farmId, int $clientId, int $userId, float $marchandise, float $taux): Sale
{
    $vente = Sale::create([
        'farm_id' => $farmId, 'uuid' => (string) Str::uuid(),
        'reference' => 'FA-' . Str::random(6), 'client_id' => $clientId,
        'sale_date' => today()->toDateString(), 'type' => 'facture_tva',
        'status' => 'valide', 'tax_rate' => $taux, 'user_id' => $userId,
    ]);

    SaleItem::create([
        'sale_id' => $vente->id, 'product_type' => 'oeufs', 'product_name' => 'Alvéole',
        'quantity' => 1, 'unit_price' => $marchandise, 'total' => $marchandise,
    ]);

    $vente->recalculateTotals();

    return $vente->fresh();
}

test('la TVA collectée est TOTALISÉE sur la période', function () {
    /*
     * Ce qu'aucun écran ne faisait. Deux factures à 18 % sur un million chacune :
     * 360 000 collectés pour l'État.
     */
    factureTaxee($this->farm->id, $this->client->id, $this->adminUser->id, 1_000_000, 18);
    factureTaxee($this->farm->id, $this->client->id, $this->adminUser->id, 1_000_000, 18);

    expect(PeriodRevenue::taxCollected(now()->startOfYear(), now()->endOfDay()))
        ->toBe(360_000.0);
});

test('elle n’entre PAS dans les recettes', function () {
    // La borne de #310 : cet argent n'est pas du chiffre d'affaires.
    factureTaxee($this->farm->id, $this->client->id, $this->adminUser->id, 1_000_000, 18);

    $recettes = array_sum(PeriodRevenue::byProductType(now()->startOfYear(), now()->endOfDay()));

    expect($recettes)->toBe(1_000_000.0)
        ->and(PeriodRevenue::taxCollected(now()->startOfYear(), now()->endOfDay()))->toBe(180_000.0);
});

test('un BROUILLON ne collecte rien', function () {
    /*
     * Même règle que partout : un document non engagé ne produit aucun effet.
     * Une facture préparée mais non validée ne crée pas de dette fiscale.
     */
    $vente = factureTaxee($this->farm->id, $this->client->id, $this->adminUser->id, 1_000_000, 18);
    $vente->update(['status' => 'brouillon']);

    expect(PeriodRevenue::taxCollected(now()->startOfYear(), now()->endOfDay()))->toBe(0.0);
});

test('le compte de résultat l’AFFICHE, hors du total', function () {
    factureTaxee($this->farm->id, $this->client->id, $this->adminUser->id, 1_000_000, 18);

    $reponse = $this->get(route('reports.profit_loss'))->assertOk();

    expect((float) $reponse->viewData('taxCollected'))->toBe(180_000.0)
        ->and((float) $reponse->viewData('totalRevenue'))->toBe(1_000_000.0);

    expect(str_contains($reponse->getContent(), 'TVA collectée'))->toBeTrue();
});

test('l’écran REFUSE de la présenter comme un montant à reverser', function () {
    /*
     * LA borne qui compte le plus ici. La TVA due vaut « collectée − déductible »,
     * et la déductible n'est enregistrée nulle part. Présenter cette moitié comme
     * le montant à payer fonderait une déclaration fiscale sur un chiffre
     * toujours trop élevé.
     */
    factureTaxee($this->farm->id, $this->client->id, $this->adminUser->id, 1_000_000, 18);

    $html = $this->get(route('reports.profit_loss'))->getContent();

    expect(str_contains($html, 'déductible'))
        ->toBeTrue('L’écran doit dire pourquoi ce n’est pas le montant à reverser.')
        ->and(str_contains($html, 'TVA à payer'))->toBeFalse()
        ->and(str_contains($html, 'TVA à reverser</'))->toBeFalse();
});

test('sans facture taxée, rien ne s’affiche', function () {
    // Une exploitation non assujettie ne doit pas voir une ligne à zéro.
    $vente = Sale::create([
        'farm_id' => $this->farm->id, 'uuid' => (string) Str::uuid(),
        'reference' => 'BL-' . Str::random(6), 'client_id' => $this->client->id,
        'sale_date' => today()->toDateString(), 'type' => 'bon_livraison',
        'status' => 'valide', 'tax_rate' => 0, 'user_id' => $this->adminUser->id,
    ]);
    SaleItem::create([
        'sale_id' => $vente->id, 'product_type' => 'oeufs', 'product_name' => 'Alvéole',
        'quantity' => 1, 'unit_price' => 500_000, 'total' => 500_000,
    ]);
    $vente->recalculateTotals();

    $reponse = $this->get(route('reports.profit_loss'))->assertOk();

    expect((float) $reponse->viewData('taxCollected'))->toBe(0.0)
        ->and(str_contains($reponse->getContent(), 'TVA collectée'))->toBeFalse();
});
