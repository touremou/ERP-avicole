<?php

use App\Actions\Sale\CreateSale;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\DashboardInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN BROUILLON N'ENGAGE PERSONNE — MAIS IL PESAIT SUR L'ENCOURS ET SUR LE CRÉDIT.
 *
 * Signalé par l'exploitation, à partir d'une facture bien réelle : « une facture
 * en statut brouillon génère un encours, est-ce normal ? »
 *
 * Non. Et en tirant le fil, le même oubli se présentait sous DEUX formes.
 *
 * ─── 1. L'ENCOURS DU TABLEAU DE BORD ───
 *
 * `Sale::scopeUnpaid()` ne regardait que `payment_status`, jamais `status`. Une
 * vente naît en brouillon avec `payment_status = 'impaye'` : elle entrait donc
 * dans l'encours dès sa création.
 *
 * La règle était pourtant écrite DIX LIGNES PLUS HAUT, dans le scope frère
 * `scopeOpenReceivablesForSync()`, qui exclut bien brouillon et annulé. Et deux
 * appelants sur trois rattrapaient l'oubli à la main en chaînant `->validated()`
 * — l'écran Ventes et l'écran Commerce. Le tableau de bord, non. Deux chiffres
 * différents pour la même question, selon l'écran ouvert.
 *
 * ─── 2. LE SOLDE CLIENT, ET LÀ C'EST PIRE ───
 *
 * `Client::recalculateBalance()` excluait les brouillons du DÉBIT (les ventes)
 * mais pas du CRÉDIT (les paiements). Un acompte encaissé sur une vente restée
 * en brouillon était donc DÉDUIT d'un solde où la vente n'était jamais ENTRÉE.
 *
 * Ce n'est pas un cas d'école : `CreateSale` crée la vente en brouillon PUIS y
 * attache le paiement immédiat, et ni le formulaire bureau ni la synchro terrain
 * ne valident derrière. Pire, `CancelSale` refuse d'annuler une vente porteuse de
 * paiements : le brouillon payé ne peut plus être nettoyé, et fausse chaque
 * recalcul déclenché par une AUTRE vente du même client.
 *
 * Conséquence concrète : solde sous-évalué, crédit disponible sur-évalué, donc de
 * la marchandise qui sort à crédit AU-DELÀ du plafond accordé.
 *
 * Ici encore le frère faisait bon : `Provider::outstandingDebt()` se dit
 * « symétrique de Client::recalculateBalance() » et filtre, lui, les deux jambes.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->client = Client::create([
        'farm_id'      => $this->farm->id,
        'client_id'    => 'CLI-' . Str::random(6),
        'name'         => 'Boutique du marché',
        'type'         => 'entreprise',
        'category'     => 'grossiste',
        'status'       => 'actif',
        'credit_limit' => 30_000,
    ]);
});

/** Une vente au statut voulu, non soldée. */
function vente(int $farmId, int $clientId, string $statut, float $montant, float $paye = 0): Sale
{
    return Sale::create([
        'farm_id'        => $farmId,
        'uuid'           => (string) Str::uuid(),
        'reference'      => 'FA-' . Str::random(6),
        'client_id'      => $clientId,
        'sale_date'      => today()->toDateString(),
        'type'           => 'facture_tva',
        'status'         => $statut,
        'subtotal'       => $montant,
        'total_amount'   => $montant,
        'paid_amount'    => $paye,
        'payment_status' => $paye <= 0 ? 'impaye' : ($paye < $montant ? 'partiel' : 'solde'),
        'user_id'        => auth()->id(),
    ]);
}

test('un BROUILLON n’entre pas dans l’encours', function () {
    /*
     * La question posée, dans sa forme la plus simple.
     */
    vente($this->farm->id, $this->client->id, 'brouillon', 25_000);

    expect(Sale::unpaid()->count())->toBe(0);
});

test('une vente VALIDÉE non soldée y entre bien', function () {
    // La borne : on écarte le brouillon, pas la vraie créance.
    vente($this->farm->id, $this->client->id, 'valide', 25_000);

    expect(Sale::unpaid()->count())->toBe(1);
});

test('une vente ANNULÉE n’y entre pas non plus', function () {
    vente($this->farm->id, $this->client->id, 'annule', 25_000);

    expect(Sale::unpaid()->count())->toBe(0);
});

test('le TABLEAU DE BORD annonce le même encours que l’écran Commerce', function () {
    /*
     * LA divergence signalée : l'écran Commerce chaînait `->validated()` à la
     * main, le tableau de bord non. Les deux répondaient à la même question avec
     * deux chiffres.
     */
    vente($this->farm->id, $this->client->id, 'brouillon', 25_000);
    vente($this->farm->id, $this->client->id, 'valide', 40_000);

    $tableauDeBord = app(DashboardInsightsService::class)
        ->financial(now()->startOfMonth(), now()->endOfMonth())['receivables'];

    $commerce = (float) (Sale::unpaid()->validated()->sum('total_amount')
        - Sale::unpaid()->validated()->sum('paid_amount'));

    expect((float) $tableauDeBord)->toBe($commerce)
        ->and((float) $tableauDeBord)->toBe(40_000.0);
});

test('un ACOMPTE sur un brouillon ne fait pas baisser le solde du client', function () {
    /*
     * LE défaut le plus coûteux. La vente brouillon n'entre pas au débit ; son
     * acompte ne doit donc pas entrer au crédit. Sinon le client paraît devoir
     * moins qu'il ne doit.
     */
    $brouillon = vente($this->farm->id, $this->client->id, 'brouillon', 20_000, 5_000);
    Payment::create([
        'sale_id'      => $brouillon->id,
        'amount'       => 5_000,
        'payment_date' => today()->toDateString(),
        'method'       => 'especes',
        'received_by'  => $this->adminUser->id,
    ]);

    vente($this->farm->id, $this->client->id, 'valide', 10_000);

    $this->client->recalculateBalance();

    // Il doit 10 000 — pas 5 000.
    expect((float) $this->client->fresh()->balance)->toBe(10_000.0);
});

test('le CRÉDIT DISPONIBLE ne s’ouvre pas grâce à un brouillon', function () {
    /*
     * La conséquence qui coûte de la marchandise : le plafond de crédit se lit
     * sur le solde. Un solde sous-évalué laisse sortir plus que ce qui est
     * accordé.
     */
    $brouillon = vente($this->farm->id, $this->client->id, 'brouillon', 20_000, 5_000);
    Payment::create([
        'sale_id'      => $brouillon->id,
        'amount'       => 5_000,
        'payment_date' => today()->toDateString(),
        'method'       => 'especes',
        'received_by'  => $this->adminUser->id,
    ]);

    vente($this->farm->id, $this->client->id, 'valide', 10_000);

    $this->client->recalculateBalance();

    // Plafond 30 000, dû 10 000 → 20 000 disponibles, et non 25 000.
    expect((float) $this->client->fresh()->available_credit)->toBe(20_000.0);
});

test('un client n’ayant QU’un brouillon payé n’a pas un solde négatif', function () {
    /*
     * Le cas limite du même défaut : sans vente validée en face, le crédit seul
     * rendait le solde négatif — la ferme paraissait devoir de l'argent au client.
     */
    $brouillon = vente($this->farm->id, $this->client->id, 'brouillon', 20_000, 5_000);
    Payment::create([
        'sale_id'      => $brouillon->id,
        'amount'       => 5_000,
        'payment_date' => today()->toDateString(),
        'method'       => 'especes',
        'received_by'  => $this->adminUser->id,
    ]);

    $this->client->recalculateBalance();

    expect((float) $this->client->fresh()->balance)->toBe(0.0);
});

test('le RELEVÉ client et le SOLDE du client disent le même nombre', function () {
    /*
     * Les deux étaient censés porter « le même périmètre » — le docblock du
     * relevé le disait — et divergeaient exactement du montant encaissé sur les
     * brouillons.
     */
    $brouillon = vente($this->farm->id, $this->client->id, 'brouillon', 20_000, 5_000);
    Payment::create([
        'sale_id'      => $brouillon->id,
        'amount'       => 5_000,
        'payment_date' => today()->toDateString(),
        'method'       => 'especes',
        'received_by'  => $this->adminUser->id,
    ]);

    vente($this->farm->id, $this->client->id, 'valide', 10_000);

    $this->client->recalculateBalance();

    $releve = $this->get(route('clients.statement', $this->client))->assertOk();

    expect((float) $releve->viewData('statement')['balance'])
        ->toBe((float) $this->client->fresh()->balance);
});

test('CreateSale attache toujours un paiement à un BROUILLON — le constat reste ouvert', function () {
    /*
     * Cette correction rend le CHIFFRE juste ; elle ne lève pas la contradiction
     * qui l'a produit, et qui relève d'une décision d'exploitation :
     *
     *   • `RecordPayment` REFUSE explicitement d'encaisser sur un brouillon
     *     (« Impossible d'encaisser sur une vente brouillon ») ;
     *   • `CreateSale` crée la vente en brouillon puis y attache le paiement
     *     immédiat sans passer par ce garde-fou.
     *
     * Ce test ne juge pas : il CONSTATE l'état des lieux, pour que le jour où la
     * décision sera prise (valider une vente encaissée, ou refuser l'acompte sur
     * brouillon), ce test échoue et rappelle qu'il faut le relire.
     */
    $source = file_get_contents(base_path('app/Actions/Sale/CreateSale.php'));

    expect(str_contains($source, "'status'           => 'brouillon'"))->toBeTrue()
        ->and(str_contains($source, "immediate_payment"))->toBeTrue();

    $garde = file_get_contents(base_path('app/Actions/Sale/RecordPayment.php'));

    expect(str_contains($garde, "Impossible d'encaisser sur une vente"))->toBeTrue();
});
