<?php

use App\Actions\Sale\CreateSale;
use App\Models\Batch;
use App\Models\Client;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE GARDE-FOU DE #315 N'AVAIT PAS D'ÉCRIVAIN.
 *
 * `CreateDispatch::saleAlreadyDestocked()` refuse de retirer une seconde fois ce
 * que `ValidateSale` a déjà sorti — mais seulement si l'expédition SAIT à quelle
 * vente elle se rattache. Or `dispatches.sale_id` était :
 *
 *   • validé par `DispatchController::store()` ;
 *   • lu par `CreateDispatch` ;
 *   • renseigné par AUCUN formulaire.
 *
 * En exploitation, `sale_id` était donc toujours nul et le garde ne se
 * déclenchait jamais : on vendait 100 sujets (l'effectif du lot baissait de
 * 100), on éditait le bon de livraison correspondant, et 100 de plus
 * disparaissaient du lot. Le double décompte que #315 annonçait corriger
 * subsistait entier par le chemin que les techniciens empruntent.
 *
 * ─── POURQUOI MON PROPRE TEST NE L'A PAS VU ───
 *
 * `DispatchDoesNotRemoveTwiceTest` appelle l'action DIRECTEMENT, en lui passant
 * `sale_id` à la main. Il prouve que le garde fonctionne ; il ne vérifie jamais
 * qu'un opérateur peut le déclencher. C'est pourquoi les tests d'ici passent par
 * la ROUTE HTTP : le formulaire doit proposer la vente, et le POST doit
 * transporter le lien.
 *
 * ─── CE QU'ON NE PROPOSE PAS ───
 *
 * Les brouillons et les ventes déjà expédiées. Un brouillon n'a rien déstocké —
 * le rattacher supprimerait un déstockage LÉGITIME, exactement la faute inverse.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-' . Str::random(6),
        'name' => 'Grossiste Madina', 'type' => 'entreprise', 'category' => 'grossiste', 'status' => 'actif',
    ]);

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'initial_quantity' => 500, 'current_quantity' => 500, 'status' => 'Actif',
    ]);

    $this->article = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Poulet entier',
        'category' => Stock::CAT_PRODUITS_FINIS, 'unit' => 'piece',
        'current_quantity' => 200, 'alert_threshold' => 0,
        'unit_price' => 2000, 'last_unit_price' => 2000,
    ]);
});

/** Une vente encaissée — donc validée d'office (#305), donc déjà déstockée. */
function venteAExpedier(int $clientId, Batch $lot, Stock $article, float $acompte = 1_000): \App\Models\Sale
{
    return (new CreateSale())->execute([
        'client_id' => $clientId,
        'sale_date' => today()->toDateString(),
        'type'      => 'bon_livraison',
        'tax_rate'  => 0,
        'items'     => [[
            'product_type' => 'animal_vif', 'product_name' => 'Poulet vif',
            'batch_id' => $lot->id, 'quantity' => 100, 'unit' => 'tete', 'unit_price' => 30_000,
        ], [
            'product_type' => 'produits_finis', 'product_name' => $article->item_name,
            'product_id' => $article->id, 'quantity' => 50, 'unit' => 'piece', 'unit_price' => 2_000,
        ]],
        'immediate_payment' => $acompte,
        'payment_method'    => 'especes',
    ]);
}

/** Le formulaire d'expédition, posté comme le fait le navigateur. */
function posterExpedition(array $extra = []): array
{
    return array_merge([
        'driver_name'   => 'Camara',
        'dispatch_date' => today()->toDateString(),
        'destination'   => 'Marché de Madina',
        'items'         => [[
            'product_type' => 'animal_vif', 'product_name' => 'Poulet vif',
            'quantity' => 100, 'unit' => 'tete',
        ]],
    ], $extra);
}

test('le formulaire PROPOSE la vente à honorer — le champ existe', function () {
    /*
     * L'écrivain manquant. Sans ce champ, `sale_id` reste nul quoi qu'il arrive
     * et le garde de #315 ne peut pas se déclencher.
     */
    $vente = venteAExpedier($this->client->id, $this->lot, $this->article);

    $this->get(route('dispatches.create'))
        ->assertOk()
        ->assertSee('name="sale_id"', false)
        ->assertSee($vente->reference);
});

test('vendre puis expédier PAR LE FORMULAIRE ne retire qu’une fois', function () {
    /*
     * Le défaut, mesuré de bout en bout par le chemin réel : 500 sujets, 100
     * vendus puis expédiés — il doit en rester 400, pas 300.
     */
    $vente = venteAExpedier($this->client->id, $this->lot, $this->article);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(400);   // la vente a déstocké

    $this->post(route('dispatches.store'), posterExpedition([
        'sale_id' => $vente->id,
        'items'   => [[
            'product_type' => 'animal_vif', 'product_name' => 'Poulet vif',
            'batch_id' => $this->lot->id, 'quantity' => 100, 'unit' => 'tete',
        ]],
    ]))->assertRedirect();

    expect((int) $this->lot->fresh()->current_quantity)->toBe(400);   // et l'expédition non
});

test('une expédition SANS vente déclarée déstocke toujours', function () {
    /*
     * La moitié symétrique. Le champ est facultatif : un transfert sans vente
     * enregistrée reste le fait générateur de la sortie.
     */
    $this->post(route('dispatches.store'), posterExpedition([
        'items' => [[
            'product_type' => 'animal_vif', 'product_name' => 'Poulet vif',
            'batch_id' => $this->lot->id, 'quantity' => 100, 'unit' => 'tete',
        ]],
    ]))->assertRedirect();

    expect((int) $this->lot->fresh()->current_quantity)->toBe(400);
});

test('une vente en BROUILLON n’est pas proposée', function () {
    /*
     * Elle n'a rien déstocké : la rattacher supprimerait un déstockage légitime.
     */
    $brouillon = venteAExpedier($this->client->id, $this->lot, $this->article, acompte: 0);

    expect($brouillon->fresh()->status)->toBe('brouillon');

    $this->get(route('dispatches.create'))
        ->assertOk()
        ->assertDontSee($brouillon->reference);
});

test('une vente DÉJÀ expédiée n’est plus proposée', function () {
    /*
     * Son bon de livraison existe ; la reproposer inviterait à en émettre un
     * second sur la même marchandise.
     */
    $vente = venteAExpedier($this->client->id, $this->lot, $this->article);

    // Elle est proposée tant qu'aucun bon ne la couvre — sinon le test suivant
    // serait vrai pour la mauvaise raison.
    $this->get(route('dispatches.create'))->assertSee($vente->reference);

    $this->post(route('dispatches.store'), posterExpedition([
        'sale_id' => $vente->id,
        'items'   => [[
            'product_type' => 'animal_vif', 'product_name' => 'Poulet vif',
            'batch_id' => $this->lot->id, 'quantity' => 100, 'unit' => 'tete',
        ]],
    ]))->assertRedirect();

    $this->get(route('dispatches.create'))
        ->assertOk()
        ->assertDontSee($vente->reference);
});
