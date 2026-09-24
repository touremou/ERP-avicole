<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Farm;
use App\Models\Provider;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA PAGE PUBLIQUE DE TRAÇABILITÉ TIENT SA PROMESSE PAR HASARD.
 *
 * `/trace/lot/{code}` est volontairement SANS authentification — un client, un
 * inspecteur ou un distributeur scanne le QR de l'étiquette et vérifie
 * l'origine. Son commentaire annonce le contrat : « On n'expose QUE des
 * informations d'origine — aucune donnée financière ».
 *
 * Le contrôleur, lui, passe le MODÈLE ENTIER à la vue, relations comprises. Ce
 * qui sort de cette page n'est donc pas décidé par le contrôleur : c'est décidé
 * par ce que la vue imprime, ligne à ligne. Aujourd'hui elle n'imprime que des
 * noms et des dates — le contrat est tenu. Rien ne l'y oblige.
 *
 * Or le lot porte `buy_price_per_unit`, `actual_sell_price_per_unit`,
 * `total_acquisition_cost` et `additional_costs`, et ses relations portent les
 * coordonnées du fournisseur et de la ferme. Une ligne ajoutée à la vue, un
 * jour, par quelqu'un qui ignore que cette page est publique, et le prix
 * d'achat d'un lot part sur l'étiquette d'un carton d'œufs.
 *
 * ─── LA RÈGLE EXISTE DÉJÀ, DANS CE MÊME FICHIER ───
 *
 * `TraceabilityController::mill()` — la page publique voisine — n'expose pas un
 * modèle mais une LISTE EXPLICITE de lignes : formule, quantité, atelier, date.
 * Ce qui n'y figure pas ne peut pas sortir. Et `SyncController` écrit la règle
 * noir sur blanc pour l'API : « liste blanche stricte — on ne sérialise JAMAIS
 * un modèle entier vers l'extérieur ».
 *
 * Deux portes publiques, deux disciplines. Ce test donne à la seconde la
 * garantie que la première tient par construction : il ne change pas la page,
 * il rend son contrat OPPOSABLE.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Un lot qui porte tout ce qui ne doit PAS sortir. */
function lotAvecSesSecrets(Farm $ferme): Batch
{
    $batiment = Building::factory()->create([
        'farm_id' => $ferme->id, 'name' => 'Poulailler A',
        'capacity' => 5000, 'status' => 'Occupé',
    ]);

    $fournisseur = Provider::factory()->create([
        'farm_id' => $ferme->id, 'name' => 'Couvoir Kindia',
        'phone'   => '624999888',
        'status'  => 'Actif',
    ]);

    return Batch::factory()->create([
        'farm_id'                    => $ferme->id,
        'code'                       => 'TRACE-PUBLIC-001',
        'status'                     => 'Actif',
        'building_id'                => $batiment->id,
        'provider_id'                => $fournisseur->id,
        'initial_quantity'           => 1000,
        'current_quantity'           => 940,
        'arrival_date'               => '2026-06-01',
        'birth_date'                 => '2026-06-01',
        'buy_price_per_unit'         => 13_750,
        'actual_sell_price_per_unit' => 42_500,
        'total_acquisition_cost'     => 13_750_000,
        'additional_costs'           => 675_000,
    ]);
}

test('la page est bien PUBLIQUE — sans elle, ce test ne prouverait rien', function () {
    /*
     * Garde-fou du garde-fou. Si la route devenait authentifiée, les
     * assertions suivantes passeraient sur une redirection vide.
     */
    $lot = lotAvecSesSecrets($this->farm);

    $this->assertGuest();

    $this->get(route('trace.batch', $lot->code))
        ->assertOk()
        ->assertSee('TRACE-PUBLIC-001');
});

test('elle montre l’ORIGINE : espèce, bâtiment, dates, fournisseur', function () {
    // Le service rendu : ce pour quoi la page existe doit continuer d'exister.
    $lot = lotAvecSesSecrets($this->farm);

    $this->get(route('trace.batch', $lot->code))
        ->assertOk()
        ->assertSee('Poulailler A')
        ->assertSee('Couvoir Kindia')
        ->assertSee('01/06/2026');
});

test('elle ne montre AUCUN prix, AUCUN coût', function () {
    /*
     * Le contrat que le commentaire annonce. Les montants sont cherchés sous
     * leurs deux formes : brute, et telle que l'application les met en forme
     * (espaces fines des milliers).
     */
    $lot = lotAvecSesSecrets($this->farm);

    $html = $this->get(route('trace.batch', $lot->code))->assertOk()->getContent();

    $montants = [
        $lot->buy_price_per_unit,
        $lot->actual_sell_price_per_unit,
        $lot->total_acquisition_cost,
        $lot->additional_costs,
    ];

    foreach ($montants as $montant) {
        foreach ([(string) (int) $montant, number_format((float) $montant, 0, ',', ' ')] as $forme) {
            // `toContain` est VARIADIQUE : lui passer un message en ferait un
            // second motif à chercher, et la garde ne garderait plus rien.
            expect(str_contains($html, $forme))
                ->toBeFalse("montant exposé publiquement : {$forme}");
        }
    }
});

test('ni les COORDONNÉES du fournisseur ou de la ferme', function () {
    /*
     * « Informations d'origine » nomme un fournisseur ; le joindre est autre
     * chose. Un numéro de téléphone sur une étiquette de carton n'est pas de
     * la traçabilité.
     */
    $lot = lotAvecSesSecrets($this->farm);

    $html = $this->get(route('trace.batch', $lot->code))->assertOk()->getContent();

    expect($html)->not->toContain('624999888');
});

test('la promesse vaut pour TOUT champ monétaire du lot — dérivé, pas listé', function () {
    /*
     * La garde qui tient dans le temps. Énumérer quatre colonnes à la main
     * reproduirait le défaut qu'on surveille : la liste vieillirait, et une
     * colonne d'argent ajoutée demain ne serait contrôlée par personne.
     *
     * On interroge donc le SCHÉMA : toute colonne de lot dont le nom parle
     * d'argent doit rester hors de la page. Le montant est rendu improbable
     * (et non rond) pour qu'il ne puisse pas se confondre avec un effectif ou
     * une date.
     */
    $lot = lotAvecSesSecrets($this->farm);

    $colonnesArgent = collect(\Illuminate\Support\Facades\Schema::getColumnListing('batches'))
        ->filter(fn ($c) => preg_match('/(cost|price|amount|revenue|margin)/i', $c));

    expect($colonnesArgent)->not->toBeEmpty();   // sinon la garde ne garde rien

    $temoin = 7_654_321;

    foreach ($colonnesArgent as $colonne) {
        \Illuminate\Support\Facades\DB::table('batches')
            ->where('id', $lot->id)
            ->update([$colonne => $temoin]);
    }

    $html = $this->get(route('trace.batch', $lot->code))->assertOk()->getContent();

    foreach ([(string) $temoin, number_format($temoin, 0, ',', ' ')] as $forme) {
        expect(str_contains($html, $forme))
            ->toBeFalse("une colonne monétaire ressort sur la page publique ({$forme})");
    }
});
