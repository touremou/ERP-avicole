<?php

use App\Models\Batch;
use App\Models\FeedPurchase;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « LE FORMULAIRE ACHAT DIRECT NE FONCTIONNE PAS ».
 *
 * Signalé par l'exploitation. Rejoué dans un vrai navigateur, la modale
 * « Achat direct » de la fiche lot cumulait trois défauts :
 *
 *   • UNITÉS : elle proposait « Boîte », « Flacon », « Pcs », « Paquet » ; le
 *     serveur n'acceptait que « Sac, KG, Litre, Unité, Boite ». Un vaccin acheté
 *     en boîtes était refusé…
 *   • … HORS DE LA MODALE : seule une erreur de prix ou de quantité la
 *     rouvrait. Un refus d'unité ramenait sur la fiche, modale fermée, saisie
 *     perdue, avec pour seule trace un bandeau de 8 secondes « Le champ unit
 *     est invalide » ;
 *   • CATÉGORIE : catégorie et nature n'étaient pas transmises (retirées « pour
 *     éviter le crash SQL » d'une colonne `metadata` qui existe depuis). Un
 *     vaccin, des copeaux, une mangeoire entraient au stock comme ALIMENT — et
 *     dix sacs de copeaux devenaient 500 kg.
 *
 * Et l'étiquette « Quantité en … » ne s'affichait pas : `@json` dans un
 * attribut HTML y ferme l'attribut (voir le garde plus bas).
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'     => $this->farm->id,
        'building_id' => $this->building->id,
        'status'      => 'Actif',
    ]);
});

function achatDirect(Batch $lot, string $fournisseur, array $champs): array
{
    return array_merge([
        'batch_id'      => $lot->id,
        'purchase_date' => today()->toDateString(),
        'supplier'      => $fournisseur,
        'payment_mode'  => 'comptant',
        'unit_price'    => 20000,
    ], $champs);
}

test('un vaccin acheté en boîtes entre au stock en SANTÉ, pas en aliment', function () {
    $this->from(route('batches.show', $this->lot))
        ->post(route('feed-purchases.store'), achatDirect($this->lot, $this->provider->name, [
            'feed_type' => 'VACCIN HB1', 'quantity' => 2, 'unit' => 'Boite',
            'category'  => 'conso', 'metadata' => ['conso_type' => 'Santé'],
        ]))
        ->assertSessionHasNoErrors();

    $achat = FeedPurchase::where('feed_type', 'VACCIN HB1')->firstOrFail();
    $stock = Stock::where('item_name', 'VACCIN HB1')->firstOrFail();

    expect($achat->unit)->toBe('Boite')
        ->and($achat->metadata['conso_type'])->toBe('Santé')
        ->and($stock->category)->toBe('conso')
        ->and($stock->metadata['conso_type'])->toBe('Santé')
        ->and((float) $stock->current_quantity)->toBe(2.0);
});

test('dix sacs de copeaux restent dix sacs de LITIÈRE — pas 500 kg d’aliment', function () {
    $this->from(route('batches.show', $this->lot))
        ->post(route('feed-purchases.store'), achatDirect($this->lot, $this->provider->name, [
            'feed_type' => 'COPEAUX', 'quantity' => 10, 'unit' => 'Sac', 'category' => 'litieres',
        ]))
        ->assertSessionHasNoErrors();

    $stock = Stock::where('item_name', 'COPEAUX')->firstOrFail();

    expect($stock->category)->toBe('litieres')
        ->and($stock->unit)->toBe('Sac')
        ->and((float) $stock->current_quantity)->toBe(10.0);
});

test('chaque unité proposée par la modale est acceptée par le serveur', function () {
    // Les commentaires Blade (qui racontent ce défaut) ne comptent pas.
    $modale = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(resource_path('views/batches/partials/feed-modal.blade.php')));
    preg_match('/get availableUnits\(\) \{(.*?)\n        \},/s', $modale, $bloc);
    preg_match_all("/'([^']+)'/", $bloc[1], $valeurs);

    $unites = array_diff(array_unique($valeurs[1]), ['conso', 'Aliment', 'litieres', 'materiels']);

    expect($unites)->not->toBeEmpty()
        ->and(array_values(array_diff($unites, FeedPurchase::UNITES)))->toBe([]);
});

test('un refus du serveur rouvre la modale et s’y affiche', function () {
    $page = $this->from(route('batches.show', $this->lot))
        ->followingRedirects()
        ->post(route('feed-purchases.store'), achatDirect($this->lot, $this->provider->name, [
            'feed_type' => 'MANGEOIRE', 'quantity' => 3, 'unit' => 'Carton', 'category' => 'materiels',
        ]))
        ->assertOk()
        ->getContent();

    // La modale se rouvre sur l'erreur d'unité (l'appel `openFeedModal();`
    // n'est émis que sur erreur ; le bouton du menu l'appelle sans
    // point-virgule), et l'affiche.
    expect(substr_count($page, 'openFeedModal();'))->toBe(1)
        ->and($page)->toContain(__('validation.in', ['attribute' => 'unit']));

    expect(FeedPurchase::count())->toBe(0);
});

test('la fenêtre s’ouvre depuis le menu « Gérer » : pas de fermeture « au clic extérieur »', function () {
    /*
     * Signalé deux fois : « le modal d'achat rapide ne s'ouvre toujours pas ».
     * La boîte portait `@click.away="closeFeedModal()"`. Le clic du menu ouvrait
     * la fenêtre (onclick, synchrone), puis ce même clic remontait au document :
     * Alpine y voyait un clic HORS de la boîte, désormais visible, et la
     * refermait aussitôt. Rejoué dans Chromium avec un VRAI clic (les essais
     * précédents appelaient openFeedModal() directement — et passaient).
     * La fenêtre se ferme désormais au clic sur son FOND (@click.self).
     */
    // Les commentaires Blade (qui racontent ce défaut) ne comptent pas.
    $modale = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(resource_path('views/batches/partials/feed-modal.blade.php')));

    expect($modale)->not->toMatch('/@click\.(away|outside)|x-on:click\.(away|outside)/')
        ->and($modale)->toContain('@click.self="closeFeedModal()"');
});
