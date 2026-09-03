<?php

use App\Models\Client;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN SEUL TAUX DE TVA, TROIS DÉCLARATIONS SUR LE MÊME ÉCRAN.
 *
 * `general.tva_rate` est un RÉGLAGE. L'écran de création de vente le lisait
 * pour DEUX choses — le libellé de l'option « Facture (TVA x%) » et le libellé
 * du récapitulatif — et le réécrivait en dur pour les deux autres :
 *
 *   • le champ caché soumis :   `:value="saleType === 'facture' ? 18 : 0"` ;
 *   • le montant affiché :      `this.net * 0.18`.
 *
 * Seule la charge utile HORS-LIGNE lisait le réglage.
 *
 * ─── CE QUE ÇA DONNE SUR UNE EXPLOITATION RÉGLÉE AILLEURS QU'À 18 ───
 *
 * `StoreSaleRequest` valide `tax_rate` par `in:0,{taux réglé}`. Le formulaire
 * soumettant 18, la facturation EN LIGNE était donc REFUSÉE — sur un champ
 * caché, que l'utilisateur ne voit pas et ne peut pas corriger — pendant que la
 * même vente saisie HORS-LIGNE passait, elle, sans encombre.
 *
 * Et le récapitulatif annonçait « TVA 20 % » au-dessus d'un montant calculé
 * à 18 : l'écran se contredisait à deux lignes d'intervalle.
 *
 * ─── LA RÈGLE ───
 *
 * Le taux vient du réglage, partout sur l'écran. Le serveur, lui, n'a pas
 * bougé : il n'accepte toujours que 0 ou le taux réglé — c'est bien le
 * formulaire qui avait tort.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-TVA',
        'name' => 'Grossiste', 'type' => 'entreprise',
        'category' => 'grossiste', 'status' => 'actif',
    ]);
});

/** Règle le taux de TVA de l'exploitation et vide le cache des réglages. */
function reglerLaTva(float $taux): void
{
    Setting::updateOrCreate(
        ['group' => 'general', 'key' => 'tva_rate'],
        ['value' => (string) $taux, 'type' => 'number', 'label' => 'Taux de TVA', 'unit' => '%'],
    );

    Cache::flush();
}

test('l’écran de vente applique le taux RÉGLÉ, pas 18 en dur', function () {
    /*
     * LE défaut : le champ soumis et le calcul affiché portaient « 18 » et
     * « 0.18 », quel que soit le réglage.
     */
    reglerLaTva(20);

    $page = $this->get(route('sales.create'));

    $page->assertOk();

    $html = $page->getContent();

    expect($html)->not->toContain("'facture' ? 18 : 0")   // le champ soumis
        ->and($html)->not->toContain('net*0.18')          // le montant affiché
        ->and($html)->toContain("'facture' ? 20 : 0")
        ->and($html)->toContain('net*20/100');
});

test('le serveur refusait bien ce que le formulaire envoyait', function () {
    /*
     * La preuve que le défaut BLOQUAIT, et ne se contentait pas de mal
     * calculer : le serveur n'accepte que 0 ou le taux réglé. Le formulaire
     * envoyant 18 sur une exploitation à 20, la facture était rejetée sur un
     * champ caché.
     */
    reglerLaTva(20);

    $client = Client::first();

    $ligne = [[
        'product_type' => 'fumier', 'product_name' => 'Fumier en vrac',
        'quantity' => 1, 'unit' => 'voyage', 'unit_price' => 100_000,
    ]];

    $this->post(route('sales.store'), [
        'client_id' => $client->id, 'sale_date' => today()->toDateString(),
        'type' => 'facture', 'tax_rate' => 18, 'items' => $ligne,
    ])->assertSessionHasErrors('tax_rate');

    // Le taux réglé, lui, passe — et c'est celui que l'écran envoie désormais.
    $this->post(route('sales.store'), [
        'client_id' => $client->id, 'sale_date' => today()->toDateString(),
        'type' => 'facture', 'tax_rate' => 20, 'items' => $ligne,
    ])->assertSessionHasNoErrors();
});

test('la TVA appliquée à la vente est bien celle du réglage', function () {
    // Bout en bout : 100 000 HT à 20 % → 120 000 TTC.
    reglerLaTva(20);

    $this->post(route('sales.store'), [
        'client_id' => Client::first()->id,
        'sale_date' => today()->toDateString(),
        'type'      => 'facture',
        'tax_rate'  => 20,
        'items'     => [[
            'product_type' => 'fumier', 'product_name' => 'Fumier en vrac',
            'quantity' => 1, 'unit' => 'voyage', 'unit_price' => 100_000,
        ]],
    ]);

    $vente = \App\Models\Sale::latest('id')->first();

    expect((float) $vente->tax_amount)->toBe(20_000.0)
        ->and((float) $vente->total_amount)->toBe(120_000.0);
});

test('le réglage par défaut reste 18 — non-régression', function () {
    /*
     * LA borne : la très grande majorité des exploitations n'a jamais touché ce
     * réglage. Rien ne doit bouger pour elles.
     */
    $page = $this->get(route('sales.create'));

    $page->assertOk();

    expect($page->getContent())->toContain("'facture' ? 18 : 0")
        ->and($page->getContent())->toContain('net*18/100');
});

test('un bon de livraison reste HORS TAXE — non-régression', function () {
    // Le taux ne s'applique qu'aux factures, quel que soit le réglage.
    reglerLaTva(20);

    $this->post(route('sales.store'), [
        'client_id' => Client::first()->id,
        'sale_date' => today()->toDateString(),
        'type'      => 'bon_livraison',
        'tax_rate'  => 0,
        'items'     => [[
            'product_type' => 'fumier', 'product_name' => 'Fumier en vrac',
            'quantity' => 1, 'unit' => 'voyage', 'unit_price' => 100_000,
        ]],
    ])->assertSessionHasNoErrors();

    $vente = \App\Models\Sale::latest('id')->first();

    expect((float) $vente->tax_amount)->toBe(0.0)
        ->and((float) $vente->total_amount)->toBe(100_000.0);
});
