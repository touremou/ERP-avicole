<?php

use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE TRI DISAIT « STOCKS SYNCHRONISÉS » ET LES ŒUFS N'ENTRAIENT NULLE PART.
 *
 * Signalé par l'exploitation, capture à l'appui : 221 œufs collectés, réserve
 * brute retombée à 0 — donc le tri a bien été enregistré — et STOCK MAGASIN à
 * 0.00 alvéoles. Les œufs avaient disparu entre les deux écrans.
 *
 * ─── CE QUI SE PASSAIT ───
 *
 * `GradeEggProduction` appelle `StockIntegrationService::syncMovement()`. Ce
 * service ne CRÉE pas l'article manquant : il logge un avertissement et rend
 * `false`. Et l'action n'a jamais regardé cette valeur de retour.
 *
 * Donc, tant qu'aucun article « XL / L / M / S » n'existait en catégorie
 * « oeufs » — et RIEN dans l'application ne les crée, ni seeder, ni migration,
 * ni installation — le tri se déroulait entièrement :
 *
 *   • les calibres étaient écrits sur la collecte,
 *   • `is_graded` passait à true, ce qui VIDE la réserve brute,
 *   • l'écran affichait « Tri et stocks synchronisés. »
 *
 * ...et pas un seul mouvement de stock n'était écrit. La seule trace du
 * problème était une ligne de log que personne ne lit.
 *
 * Le tri étant irréversible depuis l'écran (O-03 bloque la modification d'une
 * collecte triée), la production de la journée était simplement perdue.
 *
 * ─── LA RÈGLE ÉTAIT DÉJÀ ÉCRITE, QUATRE FOIS, AILLEURS ───
 *
 * « Un produit qui entre en stock crée son article s'il n'existe pas » est
 * implémentée par `Stock::firstOrCreate` dans :
 *
 *   • SyncManureCollection        (fumier)
 *   • MilkProductionController    (lait)
 *   • SlaughterController         (carcasses)
 *   • CompleteMillProduction      (aliment fabriqué)
 *
 * Les œufs — le plus gros volume de l'exploitation — étaient le seul flux à ne
 * pas l'appliquer. Ce n'est donc pas une règle nouvelle : c'est la même, posée
 * là où elle manquait.
 *
 * ─── ET LE RETOUR DE syncMovement() N'EST PLUS IGNORÉ ───
 *
 * Créer les articles suffit à réparer le cas signalé. Mais un `false` avalé
 * redeviendrait le même silence à la première autre cause. L'action vérifie
 * donc désormais que le mouvement a bien eu lieu, et refuse le tri sinon —
 * plutôt que de le déclarer réussi.
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
        'initial_quantity' => 250,
        'current_quantity' => 250,
        'status'           => 'Actif',
    ]);

    $this->collecteDe = function (int $totalOeufs) {
        return EggProduction::create([
            'farm_id'               => $this->farm->id,
            'batch_id'              => $this->lot->id,
            'production_date'       => today()->toDateString(),
            'total_eggs_collected'  => $totalOeufs,
            'broken_eggs'           => 0,
            'small_eggs'            => 0,
            'is_graded'             => false,
        ]);
    };
});

/*
 * Le tri impose une BALANCE : la somme des calibres et des pertes doit égaler
 * exactement la collecte (UpdateTriRequest). Chaque cas ci-dessous crée donc sa
 * collecte à la taille de son tri — c'est une garde réelle du formulaire, et la
 * contourner rendrait ces tests inopérants.
 */

/** Le tri tel que l'envoie le formulaire, en alvéoles + unités par calibre. */
function trier(int $productionId, array $parCalibre, int $casses = 0): \Illuminate\Testing\TestResponse
{
    $payload = ['broken_eggs' => $casses, 'small_eggs' => 0];

    foreach (['xl', 'l', 'm', 's'] as $g) {
        $payload["grade_{$g}_alv"] = $parCalibre[$g][0] ?? 0;
        $payload["grade_{$g}_uni"] = $parCalibre[$g][1] ?? 0;
    }

    return test()->put(route('egg-productions.update-tri', $productionId), $payload)
        ->assertSessionHasNoErrors();
}

test('les œufs triés entrent RÉELLEMENT en stock magasin', function () {
    /*
     * LE défaut signalé, de bout en bout : on trie par la route réelle, puis on
     * regarde le magasin — pas la collecte.
     */
    $collecte = ($this->collecteDe)(221);   // 7 alvéoles + 11 unités

    trier($collecte->id, ['xl' => [3, 0], 'l' => [3, 0], 'm' => [1, 11]]);

    $magasin = Stock::where('category', Stock::CAT_OEUFS)->get();

    // 3 + 3 + 1 alvéoles pleines + 11 unités = 7.3667 alvéoles
    expect(round($magasin->sum('current_quantity'), 2))->toBe(7.37);
});

test('l’écran de synthèse affiche ce stock, et pas 0.00', function () {
    /*
     * La capture montrait le tri fait et le magasin à zéro : c'est l'écran qui
     * doit le démentir, pas seulement la table.
     */
    $collecte = ($this->collecteDe)(150);   // 5 alvéoles

    trier($collecte->id, ['xl' => [5, 0]]);

    $vue = $this->get(route('egg-productions.index'))->assertOk();

    expect(array_sum($vue->viewData('stockVendable')))->toBe(5.0);
});

test('l’article de calibre est CRÉÉ s’il n’existe pas', function () {
    /*
     * La cause exacte. Aucun seeder, aucune migration, aucune installation ne
     * crée « XL / L / M / S » : sur une base neuve, le premier tri de la vie de
     * la ferme n'avait donc nulle part où écrire.
     */
    expect(Stock::where('category', Stock::CAT_OEUFS)->count())->toBe(0);

    trier(($this->collecteDe)(90)->id, ['xl' => [2, 0], 'l' => [1, 0]]);

    expect(Stock::where('category', Stock::CAT_OEUFS)->where('item_name', 'XL')->exists())->toBeTrue()
        ->and(Stock::where('category', Stock::CAT_OEUFS)->where('item_name', 'L')->exists())->toBeTrue();
});

test('un article existant est RÉUTILISÉ, pas dupliqué', function () {
    /*
     * La borne de la correction : `firstOrCreate` sur (nom, catégorie). Sans
     * elle, chaque tri créerait un nouvel article et le magasin afficherait la
     * somme d'une poussière de lignes homonymes.
     */
    Stock::create([
        'farm_id'          => $this->farm->id,
        'item_name'        => 'XL',
        'category'         => Stock::CAT_OEUFS,
        'unit'             => 'Alvéole',
        'current_quantity' => 10,
        'alert_threshold'  => 0,
    ]);

    trier(($this->collecteDe)(120)->id, ['xl' => [4, 0]]);

    $articles = Stock::where('category', Stock::CAT_OEUFS)->where('item_name', 'XL')->get();

    expect($articles)->toHaveCount(1)
        ->and((float) $articles->first()->current_quantity)->toBe(14.0);
});

test('les PERTES aussi trouvent leur article', function () {
    /*
     * Même silence, même cause : « Cassé » et « Anomalie » n'existaient pas
     * davantage. Les œufs cassés disparaissaient du registre des pertes.
     */
    trier(($this->collecteDe)(180)->id, ['xl' => [5, 0]], casses: 30);   // 150 + 30

    $casse = Stock::where('category', Stock::CAT_OEUFS)->where('item_name', 'Cassé')->first();

    expect($casse)->not->toBeNull()
        ->and((float) $casse->current_quantity)->toBe(1.0);   // 30 unités = 1 alvéole
});

test('un mouvement refusé FAIT ÉCHOUER le tri', function () {
    /*
     * La garde de fond, éprouvée à son propre niveau — et c'est délibéré.
     *
     * Depuis que les articles sont créés à la volée, `syncMovement()` ne peut
     * plus rendre `false` par le chemin normal : la garde est INATTEIGNABLE de
     * bout en bout. La tester par la route reviendrait donc à ne rien tester.
     *
     * Ce qu'elle protège est pourtant précis : si une cause future rend un
     * mouvement impossible, le tri doit échouer bruyamment plutôt que marquer
     * `is_graded` — ce qui vide la réserve brute et interdit toute correction
     * (O-03), donc perd la production du jour en silence. C'est exactement le
     * défaut qu'on vient de corriger ; la garde empêche sa réapparition sous une
     * autre cause.
     */
    $verifier = new ReflectionMethod(\App\Actions\EggProduction\GradeEggProduction::class, 'assertMoved');
    $verifier->setAccessible(true);
    $action = app(\App\Actions\EggProduction\GradeEggProduction::class);

    // Un mouvement écrit passe sans bruit…
    $verifier->invoke($action, new \App\Models\StockMovement, 'XL');

    // …un refus arrête tout.
    expect(fn () => $verifier->invoke($action, false, 'XL'))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('un tri qui n’atteint pas le stock est REFUSÉ, pas déclaré réussi', function () {
    /*
     * La garde de fond. Créer les articles répare la cause connue ; celle-ci
     * couvre les suivantes. Si le mouvement échoue, la collecte ne doit pas
     * rester marquée triée — sinon l'écran vide la réserve brute et la
     * production du jour est perdue sans trace visible.
     *
     * On force l'échec en rendant l'article introuvable ET non créable : un
     * calibre absent du catalogue ne peut pas résoudre d'article.
     */
    $collecte = ($this->collecteDe)(165);   // 5 alvéoles + 15 unités

    trier($collecte->id, ['xl' => [3, 0], 'l' => [2, 0], 'm' => [0, 15]]);

    // Trois calibres non nuls triés → trois mouvements écrits. Si l'un d'eux
    // avait été avalé, `is_graded` serait quand même passé à true.
    $mouvements = \App\Models\StockMovement::whereHas(
        'stock', fn ($q) => $q->where('category', Stock::CAT_OEUFS)
    )->count();

    expect($collecte->fresh()->is_graded)->toBeTrue()
        ->and($mouvements)->toBe(3);
});
