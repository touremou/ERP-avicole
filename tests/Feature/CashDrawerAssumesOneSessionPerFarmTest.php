<?php

use App\Models\CashRegisterSession;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE TIROIR-CAISSE REPOSE SUR UNE HYPOTHÈSE QUE RIEN NE PROTÉGEAIT.
 *
 * `CashRegisterSession::expectedCash()` retient TOUTES les espèces encaissées
 * dans sa fenêtre de temps, sans filtrer `treasury_account_id` — alors que la
 * session en porte un.
 *
 * C'est juste aujourd'hui, et seulement parce que `CashRegisterController::open()`
 * refuse d'ouvrir une session tant qu'une autre est ouverte sur la ferme courante.
 * Une seule caisse ouverte à la fois, donc toutes les espèces de la période lui
 * reviennent bien.
 *
 * ─── POURQUOI CE TEST EXISTE ───
 *
 * Le jour où deux caisses coexisteraient sur une même ferme — un comptoir et une
 * vente au portail — chacune attendrait la recette de l'autre. Les DEUX tiroirs
 * afficheraient un manquant égal à la recette du voisin.
 *
 * Un écart de caisse ressemble à un vol. Ce n'est pas le genre de défaut qu'on
 * veut découvrir un soir de comptage, en accusant quelqu'un.
 *
 * Ce test ne corrige rien : il FIXE le couplage. Si la règle « une seule session
 * ouverte par ferme » tombe un jour, il tombe avec elle et rappelle qu'il faut
 * alors filtrer `expectedCash()` par compte de trésorerie.
 *
 * ─── CE QUI EST DÉLIBÉRÉ, ET QU'IL NE FAUT PAS « CORRIGER » ───
 *
 * Le tiroir compte l'ARGENT REÇU, pas les créances : il retient donc les espèces
 * encaissées quel que soit l'état du document de vente — brouillon compris. C'est
 * l'inverse de la règle de l'encours (cf. DraftIsNotAReceivableTest), et c'est
 * voulu : un billet posé sur le comptoir est dans le tiroir, que la facture soit
 * validée ou non. Ne pas l'attendre ferait apparaître un EXCÉDENT au comptage.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Un encaissement en espèces, rattaché à une vente. */
function encaissementEspeces(int $farmId, int $userId, float $montant): Payment
{
    $client = \App\Models\Client::create([
        'farm_id'   => $farmId,
        'client_id' => 'CLI-' . Str::random(6),
        'name'      => 'Client comptoir',
        'type'      => 'particulier',
        'category'  => 'detaillant',
        'status'    => 'actif',
    ]);

    $vente = Sale::create([
        'farm_id'        => $farmId,
        'uuid'           => (string) Str::uuid(),
        'reference'      => 'FA-' . Str::random(6),
        'client_id'      => $client->id,
        'sale_date'      => today()->toDateString(),
        'type'           => 'bon_livraison',
        'status'         => 'valide',
        'subtotal'       => $montant,
        'total_amount'   => $montant,
        'paid_amount'    => $montant,
        'payment_status' => 'solde',
        'user_id'        => $userId,
    ]);

    return Payment::create([
        'farm_id'      => $farmId,
        'sale_id'      => $vente->id,
        'amount'       => $montant,
        'payment_date' => today()->toDateString(),
        'method'       => 'especes',
        'received_by'  => $userId,
    ]);
}

test('LA RÈGLE dont dépend le tiroir : une seule caisse ouverte par ferme', function () {
    /*
     * LE couplage. `expectedCash()` ne filtre pas le compte de trésorerie ; il ne
     * peut s'en passer que tant que cette règle tient.
     */
    CashRegisterSession::create([
        'farm_id'       => $this->farm->id,
        'user_id'       => $this->adminUser->id,
        'status'        => 'open',
        'opened_at'     => now()->subHour(),
        'opening_float' => 100_000,
    ]);

    $refus = $this->post(route('cash-register.open'), ['opening_float' => 50_000]);

    expect(CashRegisterSession::open()->count())
        ->toBe(1, 'Deux caisses ouvertes sur une ferme fausseraient les DEUX comptages.');
});

test('le tiroir attend les espèces encaissées pendant sa session', function () {
    $session = CashRegisterSession::create([
        'farm_id'       => $this->farm->id,
        'user_id'       => $this->adminUser->id,
        'status'        => 'open',
        'opened_at'     => now()->subHour(),
        'opening_float' => 100_000,
    ]);

    encaissementEspeces($this->farm->id, $this->adminUser->id, 75_000);

    expect($session->expectedCash())->toBe(175_000.0);
});

test('il n’attend PAS les espèces d’avant son ouverture', function () {
    // La borne de fenêtre : la recette de la veille est déjà partie au coffre.
    $veille = encaissementEspeces($this->farm->id, $this->adminUser->id, 60_000);
    \Illuminate\Support\Facades\DB::table('payments')
        ->where('id', $veille->id)
        ->update(['created_at' => now()->subDay()]);

    $session = CashRegisterSession::create([
        'farm_id'       => $this->farm->id,
        'user_id'       => $this->adminUser->id,
        'status'        => 'open',
        'opened_at'     => now()->subHour(),
        'opening_float' => 100_000,
    ]);

    expect($session->expectedCash())->toBe(100_000.0);
});

test('il attend l’argent d’un BROUILLON — et c’est voulu', function () {
    /*
     * L'inverse exact de la règle de l'encours, et pour une bonne raison : le
     * billet est dans le tiroir même si la facture n'est pas validée. Ne pas
     * l'attendre ferait apparaître un excédent au comptage du soir.
     *
     * Ce cas n'a rien de théorique : `CreateSale` crée la vente en brouillon puis
     * y attache le paiement immédiat.
     */
    $session = CashRegisterSession::create([
        'farm_id'       => $this->farm->id,
        'user_id'       => $this->adminUser->id,
        'status'        => 'open',
        'opened_at'     => now()->subHour(),
        'opening_float' => 0,
    ]);

    $client = \App\Models\Client::create([
        'farm_id'   => $this->farm->id,
        'client_id' => 'CLI-' . Str::random(6),
        'name'      => 'Client comptoir',
        'type'      => 'particulier',
        'category'  => 'detaillant',
        'status'    => 'actif',
    ]);

    $brouillon = Sale::create([
        'farm_id'        => $this->farm->id,
        'uuid'           => (string) Str::uuid(),
        'reference'      => 'FA-' . Str::random(6),
        'client_id'      => $client->id,
        'sale_date'      => today()->toDateString(),
        'type'           => 'facture_tva',
        'status'         => 'brouillon',
        'subtotal'       => 25_000,
        'total_amount'   => 25_000,
        'paid_amount'    => 25_000,
        'payment_status' => 'solde',
        'user_id'        => $this->adminUser->id,
    ]);

    Payment::create([
        'sale_id'      => $brouillon->id,
        'amount'       => 25_000,
        'payment_date' => today()->toDateString(),
        'method'       => 'especes',
        'received_by'  => $this->adminUser->id,
    ]);

    expect($session->expectedCash())->toBe(25_000.0);
});

test('une caisse d’une AUTRE ferme n’entre pas dans ce tiroir', function () {
    /*
     * Ce qui rend l'hypothèse tenable sur une exploitation à plusieurs sites :
     * chaque ferme a sa caisse, et la portée de ferme les sépare. Sans cela,
     * Kindia attendrait la recette de Kérouané.
     */
    $autreFerme = \App\Models\Farm::firstOrCreate(
        ['code' => 'FT-002'],
        ['name' => 'Ferme Kérouané', 'is_active' => true]
    );

    $session = CashRegisterSession::create([
        'farm_id'       => $this->farm->id,
        'user_id'       => $this->adminUser->id,
        'status'        => 'open',
        'opened_at'     => now()->subHour(),
        'opening_float' => 0,
    ]);

    encaissementEspeces($autreFerme->id, $this->adminUser->id, 90_000);

    expect($session->expectedCash())->toBe(0.0);
});
