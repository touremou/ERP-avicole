<?php

use App\Models\Client;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA REPRISE DES SOLDES CLIENTS FAUSSÉS PAR LES ACOMPTES SUR BROUILLON.
 *
 * `Client::recalculateBalance()` excluait les brouillons du DÉBIT mais pas du
 * CRÉDIT (cf. DraftIsNotAReceivableTest). La correction rend le calcul juste
 * pour l'avenir — mais `clients.balance` est une colonne STOCKÉE : les soldes
 * déjà écrits gardent leur erreur jusqu'au prochain recalcul du client, lequel
 * n'a lieu qu'à la validation, au paiement, à l'annulation ou au retour d'une de
 * ses ventes.
 *
 * Un client sans mouvement resterait donc faux indéfiniment, et son crédit
 * disponible avec.
 *
 * ─── CE QUE CES TESTS SURVEILLENT ───
 *
 * Une commande qui réécrit des soldes en production est dangereuse. Ils portent
 * donc surtout sur ce qu'elle ne doit PAS faire : écrire sans qu'on le demande,
 * toucher un client déjà juste, ou se comporter différemment à la seconde
 * exécution.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Un client, avec un solde stocké volontairement faux. */
function clientAuSolde(int $farmId, float $soldeStocke, float $plafond = 30_000): Client
{
    $client = Client::create([
        'farm_id'      => $farmId,
        'client_id'    => 'CLI-' . Str::random(6),
        'name'         => 'Boutique ' . Str::random(4),
        'type'         => 'entreprise',
        'category'     => 'grossiste',
        'status'       => 'actif',
        'credit_limit' => $plafond,
    ]);

    // On force la colonne sans passer par le modèle : on simule l'état laissé
    // en base par l'ancien calcul.
    DB::table('clients')->where('id', $client->id)->update(['balance' => $soldeStocke]);

    return $client->fresh();
}

/** Une vente du client, au statut voulu, avec un acompte éventuel. */
function venteDuClient(Client $client, string $statut, float $montant, float $acompte = 0): Sale
{
    $vente = Sale::create([
        'farm_id'        => $client->farm_id,
        'uuid'           => (string) Str::uuid(),
        'reference'      => 'FA-' . Str::random(6),
        'client_id'      => $client->id,
        'sale_date'      => today()->toDateString(),
        'type'           => 'facture_tva',
        'status'         => $statut,
        'subtotal'       => $montant,
        'total_amount'   => $montant,
        'paid_amount'    => $acompte,
        'payment_status' => $acompte <= 0 ? 'impaye' : ($acompte < $montant ? 'partiel' : 'solde'),
        'user_id'        => auth()->id(),
    ]);

    if ($acompte > 0) {
        Payment::create([
            'farm_id'      => $client->farm_id,
            'sale_id'      => $vente->id,
            'amount'       => $acompte,
            'payment_date' => today()->toDateString(),
            'method'       => 'especes',
            'received_by'  => auth()->id(),
        ]);
    }

    return $vente;
}

test('SANS --force, elle n’écrit RIEN', function () {
    /*
     * LA borne. Une commande qui réécrit des soldes doit pouvoir être lancée
     * sans risque pour voir ce qu'elle ferait.
     */
    $client = clientAuSolde($this->farm->id, 5_000);
    venteDuClient($client, 'brouillon', 20_000, 5_000);
    venteDuClient($client, 'valide', 10_000);

    $this->artisan('clients:repair-balances')
        ->expectsOutputToContain('SIMULATION')
        ->assertSuccessful();

    expect((float) $client->fresh()->balance)->toBe(5_000.0);
});

test('avec --force, le solde faussé est recalé', function () {
    // Le cas réel : 20 000 en brouillon avec 5 000 d'acompte, 10 000 validés.
    // L'ancien calcul rendait 5 000 ; le client doit 10 000.
    $client = clientAuSolde($this->farm->id, 5_000);
    venteDuClient($client, 'brouillon', 20_000, 5_000);
    venteDuClient($client, 'valide', 10_000);

    $this->artisan('clients:repair-balances --force')->assertSuccessful();

    expect((float) $client->fresh()->balance)->toBe(10_000.0);
});

test('elle est IDEMPOTENTE : la seconde passe ne trouve plus rien', function () {
    /*
     * Ce qui la rend sûre à relancer. Elle ne rejoue aucune écriture : elle
     * recalcule depuis les ventes et les paiements, et n'écrit que l'écart.
     */
    $client = clientAuSolde($this->farm->id, 5_000);
    venteDuClient($client, 'brouillon', 20_000, 5_000);
    venteDuClient($client, 'valide', 10_000);

    $this->artisan('clients:repair-balances --force')->assertSuccessful();
    $apresPremiere = (float) $client->fresh()->balance;

    $this->artisan('clients:repair-balances --force')
        ->expectsOutputToContain('aucun écart')
        ->assertSuccessful();

    expect((float) $client->fresh()->balance)->toBe($apresPremiere);
});

test('un client DÉJÀ JUSTE n’est pas touché', function () {
    // Elle ne doit pas réécrire toute la base : seulement ce qui diverge.
    $client = clientAuSolde($this->farm->id, 10_000);
    venteDuClient($client, 'valide', 10_000);

    $this->artisan('clients:repair-balances --force')
        ->expectsOutputToContain('aucun écart')
        ->assertSuccessful();

    expect((float) $client->fresh()->balance)->toBe(10_000.0);
});

test('elle couvre TOUS LES SITES en une passe', function () {
    /*
     * En console il n'y a pas de ferme courante : la portée de ferme est inerte,
     * et les quatre sites sont traités ensemble. Sans cela, la reprise ne
     * corrigerait qu'un site et laisserait les autres faux — sans le dire.
     */
    $autreFerme = \App\Models\Farm::firstOrCreate(
        ['code' => 'FT-002'],
        ['name' => 'Ferme Kérouané', 'is_active' => true]
    );

    $kindia   = clientAuSolde($this->farm->id, 0);
    $kerouane = clientAuSolde($autreFerme->id, 0);

    venteDuClient($kindia, 'valide', 10_000);
    venteDuClient($kerouane, 'valide', 7_000);

    $this->artisan('clients:repair-balances --force')->assertSuccessful();

    expect((float) $kindia->fresh()->balance)->toBe(10_000.0)
        ->and((float) $kerouane->fresh()->balance)->toBe(7_000.0);
});

test('--farm s’en tient au site demandé', function () {
    // La borne inverse : pouvoir reprendre un seul site sans toucher aux autres.
    $autreFerme = \App\Models\Farm::firstOrCreate(
        ['code' => 'FT-002'],
        ['name' => 'Ferme Kérouané', 'is_active' => true]
    );

    $kindia   = clientAuSolde($this->farm->id, 0);
    $kerouane = clientAuSolde($autreFerme->id, 0);

    venteDuClient($kindia, 'valide', 10_000);
    venteDuClient($kerouane, 'valide', 7_000);

    $this->artisan('clients:repair-balances --force --farm=' . $this->farm->id)
        ->assertSuccessful();

    expect((float) $kindia->fresh()->balance)->toBe(10_000.0)
        ->and((float) $kerouane->fresh()->balance)->toBe(0.0);
});

test('elle ANNONCE le sens de l’écart avant d’écrire', function () {
    /*
     * Le défaut déduisait un acompte jamais crédité : les soldes stockés sont
     * donc trop BAS, et la reprise fait REMONTER des créances. Il faut le dire —
     * un promoteur qui voit ses créances augmenter sans explication a raison de
     * s'inquiéter.
     */
    $client = clientAuSolde($this->farm->id, 5_000);
    venteDuClient($client, 'brouillon', 20_000, 5_000);
    venteDuClient($client, 'valide', 10_000);

    $this->artisan('clients:repair-balances')
        ->expectsOutputToContain('doivent PLUS que le solde affiché')
        ->assertSuccessful();
});

test('la formule n’est PAS recopiée dans la commande', function () {
    /*
     * La garde qui compte le plus ici. Recopier le calcul dans la reprise aurait
     * recréé, à l'endroit exact de la correction, le défaut qu'elle répare : deux
     * déclarations de la même règle, libres de diverger.
     */
    $source = file_get_contents(base_path('app/Console/Commands/RepairClientBalances.php'));

    expect(str_contains($source, 'computedBalance()'))->toBeTrue()
        ->and(str_contains($source, "whereNotIn('status'"))
        ->toBeFalse('La commande doit déléguer au modèle, pas recopier la formule.');
});
