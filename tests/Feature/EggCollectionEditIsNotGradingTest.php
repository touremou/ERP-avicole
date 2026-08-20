<?php

use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CORRIGER UNE COLLECTE N'EST PAS LA TRIER — SIGNALÉ PAR L'EXPLOITATION.
 *
 * « Le formulaire boutons de modification ou de correction de collecte impose le
 * tri, alors que c'est 2 opérations distinctes. »
 *
 * C'était exact, et la conséquence allait plus loin que la gêne : la correction
 * n'était jamais écrite.
 *
 * ─── CE QUI SE PASSAIT ───
 *
 * Le bouton « Modifier » menait à `edit.blade.php`, dont le formulaire postait
 * vers `update-tri` — l'écran de calibrage, pas celui de la récolte. Donc :
 *
 *   1. les calibres devenaient OBLIGATOIRES pour valider une simple rectification ;
 *   2. `UpdateTriRequest` équilibrait le tri contre le total EN BASE, jamais
 *      contre celui qu'on venait de saisir : corriger 221 → 212 imposait de faire
 *      somme 221, sous peine de « Balance incorrecte » ;
 *   3. `GradeEggProduction` n'écrit pas `total_eggs_collected` : la valeur
 *      corrigée était donc JETÉE, en silence, après tout ce parcours ;
 *   4. et la journée repartait marquée triée.
 *
 * `EggProductionController::update()` — la seule méthode qui écrit réellement le
 * total — n'était atteignable depuis aucune vue. Un champ que le formulaire
 * propose et qu'aucun rédacteur ne consomme : exactement la forme du défaut de
 * la date de naissance (#295).
 *
 * ─── LA RÈGLE INDUSTRIELLE ───
 *
 * Compter les œufs sortis du bâtiment et les répartir par calibre sont deux
 * faits distincts, constatés à deux moments, souvent par deux personnes. Ils ont
 * chacun leur écran :
 *
 *   • `edit` → récolte brute, pertes, observations. Jamais de calibres ;
 *   • `tri`  → répartition par calibre, balance, mouvement de stock.
 *
 * ─── CE QUI LES RELIE QUAND MÊME ───
 *
 * Sur une journée DÉJÀ TRIÉE, corriger la récolte casse la balance
 * « trié = collecté » : la répartition ne correspond plus aux œufs déclarés. On
 * rouvre alors le tri — stock défait, calibres remis à zéro, journée revenue en
 * réserve brute — plutôt que de garder en magasin des alvéoles qui ne
 * correspondent plus à rien, ou de refuser la correction d'un chiffre faux.
 *
 * Et si les œufs sont déjà partis (vente, expédition), on ne peut pas les
 * ressortir du stock : la correction est refusée en le disant.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'arrival_date'     => today()->subDays(200)->toDateString(),
        'birth_date'       => today()->subDays(200)->toDateString(),
        'initial_quantity' => 500,
        'current_quantity' => 500,
        'status'           => 'Actif',
    ]);
});

/** Une collecte du jour, éventuellement déjà triée. */
function collecte(int $batchId, int $total, bool $triee = false, array $calibres = []): EggProduction
{
    return EggProduction::create(array_merge([
        'batch_id'             => $batchId,
        'production_date'      => today()->toDateString(),
        'total_eggs_collected' => $total,
        'broken_eggs'          => 0,
        'small_eggs'           => 0,
        'is_graded'            => $triee,
    ], $calibres));
}

test('l’écran de CORRECTION ne demande aucun calibre', function () {
    /*
     * LE défaut signalé. Le formulaire poste vers `update`, et ne porte plus les
     * champs de calibrage — qui ont leur propre écran.
     */
    $prod = collecte($this->lot->id, 221);

    $ecran = $this->get(route('egg-productions.edit', $prod))->assertOk();
    $html  = $ecran->getContent();

    /*
     * On vise l'attribut `action` ENTIER, guillemet fermant compris. L'URL de
     * `update` (/egg-production/5) est un préfixe de celle du tri
     * (/egg-production/5/tri) : un `str_contains` sur l'URL seule serait vrai
     * dans les deux cas, et ce test ne prouverait rien.
     */
    expect(str_contains($html, 'action="' . route('egg-productions.update', $prod) . '"'))
        ->toBeTrue('Le formulaire de correction doit poster vers update, pas vers le tri.')
        ->and(str_contains($html, 'name="grade_'))
        ->toBeFalse('La correction d’une récolte ne doit pas exiger la répartition par calibre.');
});

test('la correction du TOTAL est réellement enregistrée', function () {
    /*
     * La moitié invisible du défaut : le parcours aboutissait, et la valeur
     * corrigée n'atteignait jamais la base.
     */
    $prod = collecte($this->lot->id, 221);

    $this->put(route('egg-productions.update', $prod), [
        'total_eggs_collected' => 212,
        'broken_eggs'          => 0,
        'small_eggs'           => 0,
    ])->assertRedirect(route('egg-productions.index'));

    expect((int) $prod->fresh()->total_eggs_collected)->toBe(212);
});

test('corriger une collecte NON TRIÉE ne la marque pas triée', function () {
    // Elle passait à `is_graded = true` par le seul fait d'être corrigée, ce qui
    // la faisait disparaître de la réserve brute sans qu'un œuf soit calibré.
    $prod = collecte($this->lot->id, 221);

    $this->put(route('egg-productions.update', $prod), [
        'total_eggs_collected' => 212,
        'broken_eggs'          => 0,
        'small_eggs'           => 0,
    ]);

    expect($prod->fresh()->is_graded)->toBeFalse();
});

test('corriger une journée DÉJÀ TRIÉE rouvre le tri et défait le stock', function () {
    /*
     * La balance « trié = collecté » ne tient plus : la répartition par calibre
     * n'est plus celle des œufs déclarés. On la défait, plutôt que de laisser en
     * magasin des alvéoles qui ne correspondent à rien.
     */
    $prod = collecte($this->lot->id, 300, true, ['grade_l' => 10]);

    Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'L', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 10, 'alert_threshold' => 0,
    ]);

    $this->put(route('egg-productions.update', $prod), [
        'total_eggs_collected' => 280,
        'broken_eggs'          => 0,
        'small_eggs'           => 0,
    ])->assertRedirect(route('egg-productions.index'));

    $apres = $prod->fresh();

    expect($apres->is_graded)->toBeFalse('La journée doit revenir en réserve brute.')
        ->and((float) $apres->grade_l)->toBe(0.0, 'Les calibres doivent être remis à zéro.')
        ->and((float) Stock::where('item_name', 'L')->where('category', Stock::CAT_OEUFS)->value('current_quantity'))
        ->toBe(0.0, 'Les alvéoles entrées par ce tri doivent être ressorties.');
});

test('les calibres remis à zéro évitent un DOUBLE COMPTAGE au retri', function () {
    /*
     * LA raison de la remise à zéro, et elle n'est pas cosmétique.
     *
     * `GradeEggProduction` calcule un DELTA contre `grade_*`. Si la réouverture
     * sortait le stock sans remettre les calibres à zéro, le tri suivant
     * comparerait 10 à 10, verrait un delta nul, et ne recréditerait rien : les
     * œufs auraient disparu du magasin pour de bon.
     */
    $prod = collecte($this->lot->id, 300, true, ['grade_l' => 10]);

    Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'L', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 10, 'alert_threshold' => 0,
    ]);

    $this->put(route('egg-productions.update', $prod), [
        'total_eggs_collected' => 300, 'broken_eggs' => 5, 'small_eggs' => 0,
    ]);

    // On recalibre la journée à l'identique.
    $this->put(route('egg-productions.update-tri', $prod->fresh()), [
        'broken_eggs' => 5, 'small_eggs' => 0,
        'grade_l_alv' => 9, 'grade_l_uni' => 25,
    ]);

    expect((float) Stock::where('item_name', 'L')->where('category', Stock::CAT_OEUFS)->value('current_quantity'))
        ->toBeGreaterThan(9.0, 'Le retri doit recréditer le magasin.');
});

test('une correction SANS CHANGEMENT ne défait rien', function () {
    /*
     * La borne : rouvrir un tri coûte cher (stock défait, journée à recalibrer).
     * Ré-enregistrer les mêmes chiffres — un clic de trop — ne doit rien coûter.
     */
    $prod = collecte($this->lot->id, 300, true, ['grade_l' => 10]);

    Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'L', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 10, 'alert_threshold' => 0,
    ]);

    $this->put(route('egg-productions.update', $prod), [
        'total_eggs_collected' => 300,
        'broken_eggs'          => 0,
        'small_eggs'           => 0,
    ]);

    expect($prod->fresh()->is_graded)->toBeTrue('Rien n’a changé : le tri doit tenir.')
        ->and((float) Stock::where('item_name', 'L')->where('category', Stock::CAT_OEUFS)->value('current_quantity'))
        ->toBe(10.0);
});

test('des œufs DÉJÀ VENDUS bloquent la correction, avec le motif', function () {
    /*
     * On ne peut pas ressortir du magasin ce qui en est déjà parti : la sortie
     * rendrait le stock négatif, c'est-à-dire faux. On refuse en le disant.
     */
    $prod = collecte($this->lot->id, 300, true, ['grade_l' => 10]);

    Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'L', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 2, 'alert_threshold' => 0,   // 8 déjà vendues
    ]);

    $this->put(route('egg-productions.update', $prod), [
        'total_eggs_collected' => 280,
        'broken_eggs'          => 0,
        'small_eggs'           => 0,
    ])->assertSessionHas('error');

    expect((int) $prod->fresh()->total_eggs_collected)
        ->toBe(300, 'Refus = rien n’est écrit, ni la correction ni le stock.')
        ->and($prod->fresh()->is_graded)->toBeTrue();
});

test('les PERTES ne peuvent pas dépasser la récolte', function () {
    // Un œuf cassé fait partie des œufs ramassés.
    $prod = collecte($this->lot->id, 100);

    $this->put(route('egg-productions.update', $prod), [
        'total_eggs_collected' => 100,
        'broken_eggs'          => 80,
        'small_eggs'           => 40,
    ])->assertSessionHas('error');

    expect((int) $prod->fresh()->broken_eggs)->toBe(0);
});

test('le garde-fou des 100 % vaut aussi à la CORRECTION', function () {
    /*
     * `RecordEggCollection` refuse plus d'un œuf par sujet et par jour. La
     * correction écrivait le total sans repasser par là : le même invariant se
     * contournait par le second chemin.
     */
    $prod = collecte($this->lot->id, 400);

    $this->put(route('egg-productions.update', $prod), [
        'total_eggs_collected' => 700,           // 500 sujets
        'broken_eggs'          => 0,
        'small_eggs'           => 0,
    ])->assertSessionHas('error');

    expect((int) $prod->fresh()->total_eggs_collected)->toBe(400);
});
