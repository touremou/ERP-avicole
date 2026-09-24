<?php

use App\Models\Building;
use App\Models\Species;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DÉSACTIVER UNE ESPÈCE NE RETIRAIT PAS SON BÂTIMENT DES FILTRES.
 *
 * `SpeciesController::toggle` annonce exactement ce qu'il fait : « Désactiver la
 * masque des sélecteurs (création de lot, normes, POS…) ». L'écran des bâtiments
 * n'avait jamais été raccordé.
 *
 * Une ferme qui ne fait que de la volaille désactive porc, lapin, bovins — et
 * continue de se voir proposer PORCHERIE, LAPINIÈRE et ÉTABLE, au filtre comme
 * au formulaire de création. Le réglage existe, il est servi partout ailleurs, et
 * cet écran-là l'ignorait.
 *
 * ─── ET LA LISTE ÉTAIT ÉCRITE CINQ FOIS ───
 *
 *   • la barre de filtres de `buildings/index` ;
 *   • le sélecteur de `buildings/create` ;
 *   • celui de `buildings/edit` ;
 *   • la règle `in:` de `StoreBuildingRequest` ;
 *   • celle d'`UpdateBuildingRequest`.
 *
 * Elles DIVERGEAIENT déjà : la barre de filtres ne connaissait pas `etable`. Une
 * étable pouvait donc se créer et s'enregistrer, mais aucun filtre ne la
 * retrouvait — elle n'existait que sous « Tous ». C'est le même défaut que ce
 * dépôt a corrigé pour le vide sanitaire, le délai de paiement et la durée de
 * cycle : une règle déclarée à plusieurs endroits finit par se contredire.
 *
 * Les habitats vivent désormais dans `Building::TYPES`, une fois, et chacun
 * déclare CE QUI LE JUSTIFIE — une famille (les quatre stades avicoles ne
 * tiennent qu'à la volaille) ou des espèces nommées.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->seed(Database\Seeders\SpeciesSeeder::class);
});

/** Désactive une espèce, comme le fait l'écran des Paramètres. */
function desactiverLEspece(string $slug): void
{
    Species::where('slug', $slug)->update(['is_active' => false]);
}

/** Un bâtiment du parc, de cette vocation. */
function batimentDeType(int $farmId, string $type): Building
{
    return Building::factory()->create([
        'farm_id' => $farmId, 'name' => 'Bât ' . $type, 'type' => $type,
        'capacity' => 500, 'status' => 'Disponible',
    ]);
}

test('une espèce désactivée disparaît de la barre de filtres', function () {
    /*
     * LE défaut, tel que signalé : le porc est désactivé dans les Paramètres,
     * et « Porcherie » reste offerte comme filtre.
     */
    desactiverLEspece('porc');

    $html = $this->get(route('buildings.index'))->assertOk()->getContent();

    expect($html)->not->toContain("filterB('porcherie')")
        ->and($html)->toContain("filterB('chair')");       // la volaille, elle, reste
});

test('et du formulaire de création — les deux écrans disent la même chose', function () {
    /*
     * L'invariant. Un filtre qui se tait pendant qu'un formulaire propose
     * toujours le type n'aurait fait que déplacer la contradiction.
     */
    desactiverLEspece('porc');

    $html = $this->get(route('buildings.create'))->assertOk()->getContent();

    expect($html)->not->toContain('value="porcherie"')
        ->and($html)->toContain('value="chair"');
});

test('et le serveur REFUSE le type que l’écran n’offre plus', function () {
    /*
     * La garde qui compte : un formulaire trafiqué, un signet, un appel direct.
     * Une liste d'écran sans règle serveur n'est qu'une suggestion.
     */
    desactiverLEspece('porc');

    $this->post(route('buildings.store'), [
        'name' => 'Porcherie Nord', 'type' => 'porcherie',
        'surface' => 100, 'capacity' => 50,
    ])->assertSessionHasErrors('type');

    expect(Building::where('name', 'Porcherie Nord')->exists())->toBeFalse();
});

test('toutes les volailles désactivées : les quatre stades s’en vont ensemble', function () {
    /*
     * Les stades avicoles — poussinière, chair, ponte, reproducteur — ne
     * tiennent à AUCUNE espèce en particulier : ils tiennent à la famille. Les
     * rattacher au seul poulet aurait fait disparaître « Chair » d'une ferme
     * qui n'élève que des pintades.
     */
    Species::where('family', 'volaille')->update(['is_active' => false]);

    $types = array_keys(Building::typesActifs());

    expect($types)->not->toContain('chair')
        ->and($types)->not->toContain('ponte')
        ->and($types)->not->toContain('poussiniere')
        ->and($types)->not->toContain('reproducteur');
});

test('une seule volaille active SUFFIT à garder les stades', function () {
    // La borne de la règle précédente : la pintade seule tient les quatre.
    Species::where('family', 'volaille')->update(['is_active' => false]);
    Species::where('slug', 'pintade')->update(['is_active' => true]);

    expect(array_keys(Building::typesActifs()))->toContain('chair', 'ponte', 'poussiniere', 'reproducteur');
});

test('« Mixte » reste offert même sans AUCUNE espèce active', function () {
    /*
     * LA borne qui empêche l'écran de se vider. Un bâtiment mixte accueille
     * tout : le retirer laisserait une ferme neuve sans aucun type à choisir,
     * donc incapable de déclarer son premier bâtiment.
     */
    Species::query()->update(['is_active' => false]);

    expect(array_keys(Building::typesActifs()))->toBe(['mixte']);
});

test('un bâtiment EXISTANT reste filtrable, espèce désactivée ou non', function () {
    /*
     * LA borne essentielle, et l'erreur qu'il aurait été facile de commettre :
     * retirer le filtre d'un type que le parc porte encore rendrait ces
     * bâtiments visibles sous « Tous » sans qu'aucun filtre ne puisse les
     * isoler. On aurait remplacé un filtre de trop par un filtre manquant.
     */
    batimentDeType($this->farm->id, 'porcherie');
    desactiverLEspece('porc');

    $html = $this->get(route('buildings.index'))->assertOk()->getContent();

    expect($html)->toContain("filterB('porcherie')");
});

test('et sa fiche reste modifiable sans changer de nature', function () {
    /*
     * Le corollaire : rouvrir la fiche d'une porcherie existante doit toujours
     * proposer « porcherie », sinon le premier enregistrement — même pour
     * corriger une capacité — en changerait silencieusement la vocation.
     */
    $porcherie = batimentDeType($this->farm->id, 'porcherie');
    desactiverLEspece('porc');

    $html = $this->get(route('buildings.edit', $porcherie))->assertOk()->getContent();
    expect($html)->toContain('value="porcherie"');

    $this->put(route('buildings.update', $porcherie), [
        'name' => $porcherie->name, 'type' => 'porcherie',
        'surface' => 100, 'capacity' => 800, 'status' => 'Disponible',
    ])->assertSessionHasNoErrors();

    expect($porcherie->fresh()->capacity)->toBe(800)
        ->and($porcherie->fresh()->type)->toBe('porcherie');
});

test('l’ÉTABLE est proposée au filtre — elle n’y figurait pas', function () {
    /*
     * La divergence qui existait déjà, avant même la question des espèces : le
     * type était enregistrable par le formulaire et par la validation, mais la
     * barre de filtres l'ignorait. Dériver d'une seule déclaration la répare
     * sans qu'on ait eu à la chercher.
     */
    $html = $this->get(route('buildings.index'))->assertOk()->getContent();

    expect($html)->toContain("filterB('etable')");
});

test('la déclaration est UNIQUE : plus aucun écran ne porte sa propre liste', function () {
    /*
     * La garde qui empêche la divergence de renaître. Un sixième lecteur qui
     * réécrirait la liste rouvrirait exactement ce défaut — et c'est ainsi
     * qu'il est né.
     */
    $sources = [
        resource_path('views/buildings/index.blade.php'),
        resource_path('views/buildings/create.blade.php'),
        resource_path('views/buildings/edit.blade.php'),
        base_path('app/Http/Requests/Building/StoreBuildingRequest.php'),
        base_path('app/Http/Requests/Building/UpdateBuildingRequest.php'),
    ];

    foreach ($sources as $fichier) {
        // Les commentaires CITENT l'ancien code pour l'expliquer : les compter
        // ferait échouer cette garde sur sa propre documentation.
        $sansCommentaires = preg_replace(
            ['#/\*.*?\*/#s', '#//[^\n]*#', '#\{\{--.*?--\}\}#s'],
            '',
            file_get_contents($fichier),
        );

        expect($sansCommentaires)->not->toContain('lapiniere', "liste en dur dans {$fichier}");
    }
});
