<?php

use App\Models\Batch;
use App\Models\ChickDispatch;
use App\Models\Client;
use App\Models\Incubation;
use App\Models\Incubator;
use App\Models\Sale;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA VENTE DE POUSSINS N'EXISTAIT QUE DANS LE COUVOIR.
 *
 * L'écran de dispatch propose quatre destinations pour les poussins éclos :
 * élevage, stock, perte… et VENTE. Cette dernière écrivait un client, un prix
 * unitaire et un montant total sur la ligne de dispatch, affichait « X poussins
 * vendus à Y — Z GNF », et s'arrêtait là.
 *
 * AUCUNE VENTE N'ÉTAIT CRÉÉE. Donc : aucune créance au compte du client, aucune
 * ligne au journal des ventes, aucune recette au compte de résultat, rien dans
 * l'écran de recouvrement, rien dans les relances automatiques. Les poussins
 * partaient, le client devait de l'argent, et toute la chaîne commerciale
 * l'ignorait.
 *
 * Et le montant enregistré ne servait à rien : AUCUN code de cette base ne lit
 * `chick_dispatches.total_amount`. C'était une écriture sans lecteur — le motif
 * inverse, et tout aussi coûteux, de la règle sans applicateur.
 *
 * POUR UN PROMOTEUR À L'ÉTRANGER, c'est la pire forme de trou : non pas une
 * alerte qui n'arrive pas, mais une recette qui n'a jamais existé. Rien ne
 * pouvait la réclamer, puisque rien ne la connaissait.
 *
 * ─── POURQUOI UN BROUILLON ET NON UNE VENTE VALIDÉE ───
 *
 * 1. Si le bureau saisissait déjà ces ventes à la main — ce que l'absence de
 *    tout enregistrement rendait NÉCESSAIRE — une vente validée d'office ferait
 *    double emploi et gonflerait le chiffre d'affaires. Un brouillon se
 *    supprime ; une vente validée qui porte un paiement, non (#238).
 * 2. La validation porte les règles commerciales (plafond crédit, statut du
 *    client — #237). Les faire échouer ici ferait perdre l'enregistrement
 *    ZOOTECHNIQUE de l'éclosion, qui, lui, est un fait constaté.
 * 3. C'est la convention de cette base : ce qui vient du terrain naît en
 *    brouillon, la validation et le déstockage se font au bureau.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-POUS',
        'name' => 'Ferme Bantignel', 'type' => 'entreprise', 'category' => 'grossiste',
        'status' => 'actif', 'credit_limit' => 0, 'balance' => 0,
    ]);

    $incubateur = Incubator::create([
        'farm_id' => $this->farm->id, 'name' => 'Couveuse 1',
        'capacity' => 5000, 'status' => 'Disponible',
    ]);

    $lotSource = Batch::factory()->create(['farm_id' => $this->farm->id, 'code' => 'REPRO-1']);

    $this->incubation = Incubation::create([
        'farm_id' => $this->farm->id, 'batch_id' => $lotSource->id,
        'incubator_id' => $incubateur->id, 'code_incubation' => 'INC-2026-001',
        'start_date' => now()->subDays(21)->toDateString(),
        'hatch_date_expected' => now()->toDateString(),
        'eggs_count' => 1000, 'fertile_eggs' => 900, 'hatched_chicks' => 800,
        'status' => 'clos',
    ]);
});

/** Dispatch de $qty poussins vendus au client, via l'écran. */
function vendrePoussins(int $incubationId, int $clientId, int $qty = 200, float $prix = 8000)
{
    return test()->post(route('chick-dispatches.store', $incubationId), [
        'destination_type' => 'vente',
        'quantity'         => $qty,
        'quality_grade'    => 'A',
        'client_id'        => $clientId,
        'unit_price'       => $prix,
    ]);
}

test('vendre des poussins CRÉE une vente', function () {
    // LE défaut : la recette n'existait nulle part hors du couvoir.
    expect(Sale::count())->toBe(0);

    vendrePoussins($this->incubation->id, $this->client->id)->assertRedirect();

    expect(Sale::count())->toBe(1);
});

test('la vente porte le client, la quantité et le prix du dispatch', function () {
    vendrePoussins($this->incubation->id, $this->client->id, 200, 8000);

    $vente = Sale::with('items')->first();

    expect($vente->client_id)->toBe($this->client->id)
        ->and((float) $vente->total_amount)->toBe(1600000.0)
        ->and($vente->items)->toHaveCount(1)
        ->and((float) $vente->items->first()->quantity)->toBe(200.0)
        ->and((float) $vente->items->first()->unit_price)->toBe(8000.0);
});

test('la vente naît en BROUILLON, pas validée', function () {
    // Le point de conception : ne pas doubler une saisie manuelle existante, et
    // ne pas faire échouer l'enregistrement zootechnique sur une règle
    // commerciale.
    vendrePoussins($this->incubation->id, $this->client->id);

    expect(Sale::first()->status)->toBe('brouillon');
});

test('le dispatch et la vente sont LIÉS', function () {
    // Sans ce lien, on ne saurait pas qu'elles se répondent — et un second
    // passage recréerait une vente.
    vendrePoussins($this->incubation->id, $this->client->id);

    $dispatch = ChickDispatch::where('destination_type', 'vente')->first();

    expect($dispatch->sale_id)->toBe(Sale::first()->id)
        ->and($dispatch->sale->reference)->toBe(Sale::first()->reference);
});

test('la vente porte une RÉFÉRENCE du service central', function () {
    // Pas de numérotation maison : c'est la leçon de #245.
    vendrePoussins($this->incubation->id, $this->client->id);

    expect(Sale::first()->reference)->toStartWith('BL-');
});

test('la trace de l’éclosion suit la vente', function () {
    // La traçabilité amont : d'où viennent ces poussins.
    vendrePoussins($this->incubation->id, $this->client->id);

    expect(Sale::first()->notes)->toContain('INC-2026-001');
});

test('les autres destinations ne créent AUCUNE vente', function () {
    // On ne transforme pas une perte ou une mise en stock en recette.
    test()->post(route('chick-dispatches.store', $this->incubation->id), [
        'destination_type' => 'perte',
        'quantity'         => 50,
        'quality_grade'    => 'C',
    ])->assertRedirect();

    test()->post(route('chick-dispatches.store', $this->incubation->id), [
        'destination_type' => 'stock',
        'quantity'         => 100,
        'quality_grade'    => 'A',
    ])->assertRedirect();

    expect(Sale::count())->toBe(0)
        ->and(ChickDispatch::whereNotNull('sale_id')->count())->toBe(0);
});

test('la vente apparaît au journal des ventes', function () {
    // L'enjeu final : que le promoteur, hors site, puisse la voir.
    vendrePoussins($this->incubation->id, $this->client->id);

    $this->get(route('sales.index'))->assertOk()->assertSee(Sale::first()->reference);
});

test('le montant du dispatch reste écrit, mais n’est plus le seul témoin', function () {
    // On ne casse pas l'écran du couvoir : il garde son total. Ce qui change,
    // c'est qu'il n'est plus la seule trace de l'argent dû.
    vendrePoussins($this->incubation->id, $this->client->id, 200, 8000);

    expect((float) ChickDispatch::first()->total_amount)->toBe(1600000.0)
        ->and((float) Sale::first()->total_amount)->toBe(1600000.0);
});
