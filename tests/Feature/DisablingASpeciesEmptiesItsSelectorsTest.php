<?php

use App\Models\Formula;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Models\Protocol;
use App\Models\Species;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « ACTIF » NE VOULAIT PAS DIRE « UTILISABLE ».
 *
 * `ProductionType::scopeActive` ne regardait que son propre drapeau. Or
 * désactiver une espèce dans Paramètres › Espèces ne touche pas ses types de
 * production : `SpeciesController::toggle` n'écrit que `species.is_active`.
 *
 * Une ferme qui arrêtait le porc continuait donc de se voir proposer
 * « Engraissement (Porc) » sur TROIS écrans, tous alimentés par ce seul scope :
 *
 *   • la formule d'aliment (provenderie) ;
 *   • le plan de bande (planning) ;
 *   • le protocole sanitaire.
 *
 * Et l'écran des NORMES, que le message du toggle nomme pourtant
 * explicitement — « désactiver la masque des sélecteurs (création de lot,
 * normes, POS…) » — offrait encore toutes les espèces, actives ou non, et
 * ouvrait un onglet pour chaque type de production sans jamais regarder
 * l'espèce.
 *
 * La règle était écrite, annoncée, et appliquée aux seuls lots.
 *
 * ─── CE QUE CE CORRECTIF NE FAIT PAS ───
 *
 * Il ne cache rien de ce qui EXISTE. Un onglet de normes qui porte des normes
 * reste ouvert, et la fiche d'une formule ou d'un protocole continue d'afficher
 * le type qu'elle vise — désactiver masque les choix à venir, jamais les
 * enregistrements déjà pris.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->seed(Database\Seeders\SpeciesSeeder::class);

    /*
     * UNE ESPÈCE À L'IDENTITÉ NON AMBIGUË.
     *
     * Le référentiel livré partage le slug « engraissement » entre CINQ espèces
     * (mouton, chèvre, vache, lapin, porc). Éprouver la règle sur lui ne
     * prouverait rien : couper le porc laisse quatre autres porteurs du même
     * slug, et les écrans qui choisissent PAR SLUG — les protocoles — doivent
     * d'ailleurs continuer de l'offrir. Le premier jet de ce test s'y est
     * trompé.
     *
     * On se donne donc une espèce dont le type ne se confond avec aucun autre.
     */
    $this->autruche = Species::create([
        'slug' => 'autruche', 'name_fr' => 'Autruche', 'family' => 'volaille',
        'unit_label' => 'Tête', 'habitat_label' => 'Enclos', 'icon' => '🦤',
        'color' => 'stone', 'is_active' => true, 'sort_order' => 99,
    ]);

    $this->engraissement = ProductionType::create([
        'species_id' => $this->autruche->id, 'slug' => 'engraissement_autruche',
        'name_fr' => 'Engraissement autruche', 'is_active' => true,
    ]);
});

/** Désactive une espèce, comme le fait l'écran des Paramètres. */
function couperLEspece(Species $espece): void
{
    $espece->update(['is_active' => false]);
}

test('un type dont l’espèce est coupée n’est plus « actif »', function () {
    /*
     * LE défaut, à sa racine. Les trois écrans qui suivent ne font que lire ce
     * scope : le corriger ici les corrige tous, et empêche qu'un quatrième
     * écran hérite du trou en se branchant dessus.
     */
    expect(ProductionType::active()->pluck('slug'))->toContain('engraissement_autruche');

    couperLEspece($this->autruche);

    expect(ProductionType::active()->pluck('slug'))->not->toContain('engraissement_autruche');
});

test('tout type A une espèce — l’hypothèse du scope, verrouillée', function () {
    /*
     * La garde de l'hypothèse, et elle vient d'une erreur.
     *
     * J'avais d'abord ménagé dans le scope un cas « type sans espèce », pour
     * les types génériques hérités du mono-espèce. Ce cas N'EXISTE PAS :
     * `production_types.species_id` est NOT NULL, et `resolveOrCreate` retombe
     * sur la poule quand l'appelant ne donne rien. La garde était du code mort,
     * et aucune mutation ne la tuait — c'est ainsi qu'elle s'est fait repérer.
     *
     * Si la colonne redevenait nullable, le scope écarterait en silence tous
     * les types orphelins. Ce test échouerait d'abord.
     */
    $colonne = collect(\Illuminate\Support\Facades\Schema::getColumns('production_types'))
        ->firstWhere('name', 'species_id');

    expect($colonne['nullable'])->toBeFalse();
});

test('la formule d’aliment ne le propose plus', function () {
    couperLEspece($this->autruche);

    $html = $this->get(route('formulas.create'))->assertOk()->getContent();

    expect($html)->not->toContain('Engraissement autruche');
});

test('le plan de bande non plus', function () {
    couperLEspece($this->autruche);

    $html = $this->get(route('planning.create'))->assertOk()->getContent();

    expect($html)->not->toContain('Engraissement autruche');
});

test('le protocole sanitaire non plus', function () {
    couperLEspece($this->autruche);

    $html = $this->get(route('protocols.index'))->assertOk()->getContent();

    expect($html)->not->toContain('Engraissement autruche');
});

test('mais une FORMULE existante garde le type qu’elle vise', function () {
    /*
     * LA borne qui compte, et l'erreur facile : sans le type courant, le
     * sélecteur retomberait en silence sur un autre, et la formule changerait
     * de destination au premier enregistrement — sur un champ que personne
     * n'aurait touché.
     */
    $formule = Formula::create([
        'farm_id' => $this->farm->id, 'name' => 'Autruche croissance', 'code' => 'F-AUTR',
        'species_id' => $this->autruche->id, 'production_type_id' => $this->engraissement->id,
        'target_type' => 'engraissement_autruche', 'is_active' => true,
    ]);

    couperLEspece($this->autruche);

    $html = $this->get(route('formulas.edit', $formule))->assertOk()->getContent();

    expect($html)->toContain('Engraissement autruche');
});

test('et un PROTOCOLE existant aussi — il se désigne par son slug', function () {
    /*
     * Le même besoin, une autre clef : un protocole ne porte pas
     * `production_type_id` mais le slug, dans sa colonne `type`. Demander un
     * identifiant aux deux écrans aurait obligé celui-ci à résoudre le type
     * lui-même — c'est-à-dire à réécrire une part de la règle.
     */
    $protocole = Protocol::create([
        'farm_id' => $this->farm->id, 'name' => 'Protocole autruche',
        'type' => 'engraissement_autruche', 'strain' => 'Standard',
    ]);

    couperLEspece($this->autruche);

    $html = $this->get(route('protocols.edit', $protocole))->assertOk()->getContent();

    expect($html)->toContain('Engraissement autruche');
});

test('l’écran des NORMES ne propose plus l’espèce coupée', function () {
    /*
     * L'écran que le message du toggle nomme, et qui l'ignorait.
     */
    couperLEspece($this->autruche);

    $html = $this->get(route('batches.norms.index'))->assertOk()->getContent();

    expect($html)->not->toContain('value="' . $this->autruche->id . '"');
});

test('son ONGLET de normes se ferme aussi, faute de normes à y trouver', function () {
    /*
     * L'autre moitié de la règle des onglets — et celle qui manquait : sans ce
     * test, retirer entièrement le filtre par espèce des onglets ne cassait
     * rien. C'est le test mutant qui l'a dit.
     *
     * Un type dont l'espèce est coupée ET qui ne porte aucune norme n'a plus
     * rien à montrer : son onglet s'en va.
     */
    couperLEspece($this->autruche);

    $html = $this->get(route('batches.norms.index'))->assertOk()->getContent();

    expect($html)->not->toContain('engraissement_autruche');
});

test('mais un onglet de normes qui PORTE des normes reste ouvert', function () {
    /*
     * LA borne des onglets. Fermer l'onglet d'un type qui porte des normes les
     * rendrait inatteignables : on aurait caché la donnée au lieu de masquer un
     * choix. Même règle que pour les bâtiments existants.
     */
    ProductionNorm::create([
        'batch_type' => 'engraissement_autruche', 'week_number' => 1,
        'phase_name' => 'Démarrage', 'model_name' => 'Standard',
        'target_weight' => 8000,
    ]);

    couperLEspece($this->autruche);

    $html = $this->get(route('batches.norms.index'))->assertOk()->getContent();

    expect($html)->toContain('engraissement_autruche');
});

test('les quatre types volaille restent des onglets — non-régression', function () {
    // Le repli historique que l'écran portait déjà : il ne doit pas disparaître
    // parce qu'on a resserré la dérivation.
    Species::query()->update(['is_active' => false]);

    $html = $this->get(route('batches.norms.index'))->assertOk()->getContent();

    foreach (['chair', 'ponte', 'poussiniere', 'reproducteur'] as $attendu) {
        expect($html)->toContain($attendu);
    }
});
