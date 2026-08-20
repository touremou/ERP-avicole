<?php

use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA REPRISE DES TRIS QUI N'ONT JAMAIS ATTEINT LE MAGASIN.
 *
 * `GradeEggProduction` répare les tris à VENIR. Restent ceux déjà faits : leurs
 * calibres sont écrits sur la collecte — la donnée n'est pas perdue — mais le
 * stock ne les a jamais vus, et l'écran de synthèse affiche donc 0.00 pour une
 * production bien réelle.
 *
 * `eggs:repair-stock` reporte l'écart. Ce qu'on éprouve ici, c'est surtout ce
 * qu'elle ne doit PAS faire : rejouer, double-compter, ou écrire sans qu'on le
 * lui demande.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'arrival_date'     => today()->subDays(200)->toDateString(),
        'birth_date'       => today()->subDays(200)->toDateString(),
        'initial_quantity' => 250,
        'current_quantity' => 250,
        'status'           => 'Actif',
    ]);
});

/** Une collecte DÉJÀ triée, telle que la base la porte après le défaut. */
function collecteTrieeSansStock(int $farmId, int $batchId, array $calibres, int $casses = 0): EggProduction
{
    return EggProduction::create(array_merge([
        'farm_id'              => $farmId,
        'batch_id'             => $batchId,
        'production_date'      => today()->toDateString(),
        'total_eggs_collected' => 221,
        'broken_eggs'          => $casses,
        'small_eggs'           => 0,
        'is_graded'            => true,
    ], $calibres));
}

test('la SIMULATION n’écrit rien', function () {
    /*
     * La convention des commandes qui réécrivent des chiffres : montrer d'abord.
     * Une reprise qui s'appliquerait au simple appel serait un piège.
     */
    collecteTrieeSansStock($this->farm->id, $this->lot->id, ['grade_xl' => 5]);

    $this->artisan('eggs:repair-stock')->assertSuccessful();

    expect(Stock::where('category', Stock::CAT_OEUFS)->count())->toBe(0);
});

test('--force reporte au magasin ce que le tri avait compté', function () {
    // Le cas signalé : la collecte porte ses calibres, le magasin est vide.
    collecteTrieeSansStock($this->farm->id, $this->lot->id, ['grade_xl' => 5, 'grade_l' => 2.3667]);

    $this->artisan('eggs:repair-stock --force')->assertSuccessful();

    $magasin = Stock::where('category', Stock::CAT_OEUFS)->get();

    expect(round($magasin->sum('current_quantity'), 2))->toBe(7.37);
});

test('la relancer DEUX FOIS ne double pas le stock', function () {
    /*
     * LA borne. Une reprise qui rejouerait les tris au lieu de combler l'écart
     * transformerait une production invisible en une production doublée — pire
     * que le défaut qu'elle corrige.
     */
    collecteTrieeSansStock($this->farm->id, $this->lot->id, ['grade_xl' => 6]);

    $this->artisan('eggs:repair-stock --force')->assertSuccessful();
    $this->artisan('eggs:repair-stock --force')->assertSuccessful();

    expect((float) Stock::where('item_name', 'XL')->where('category', Stock::CAT_OEUFS)->first()->current_quantity)
        ->toBe(6.0);
});

test('un mouvement DÉJÀ porté n’est pas recompté', function () {
    /*
     * Une exploitation dont une partie des tris a abouti — articles créés à la
     * main, par exemple — ne doit recevoir que le manque, pas le total.
     */
    collecteTrieeSansStock($this->farm->id, $this->lot->id, ['grade_xl' => 10]);

    // 4 alvéoles ont déjà trouvé leur chemin.
    $stock = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'XL', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 4, 'alert_threshold' => 0,
    ]);
    \App\Models\StockMovement::create([
        'farm_id' => $this->farm->id, 'stock_id' => $stock->id,
        'user_id' => $this->adminUser->id,
        'type' => 'in', 'quantity' => 4, 'notes' => 'Entrée antérieure',
    ]);

    $this->artisan('eggs:repair-stock --force')->assertSuccessful();

    expect((float) $stock->fresh()->current_quantity)->toBe(10.0);
});

test('les œufs SORTIS ne sont pas re-crédités', function () {
    /*
     * La nuance qui distingue « jamais entré » de « entré puis vendu ». En ne
     * lisant que le stock courant, une vente aurait ressemblé à un tri manquant,
     * et la reprise aurait recréé les œufs vendus.
     */
    collecteTrieeSansStock($this->farm->id, $this->lot->id, ['grade_xl' => 8]);

    $stock = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'XL', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 3, 'alert_threshold' => 0,
    ]);
    foreach ([['in', 8], ['out', 5]] as [$type, $qte]) {
        \App\Models\StockMovement::create([
            'farm_id' => $this->farm->id, 'stock_id' => $stock->id,
            'user_id' => $this->adminUser->id,
            'type' => $type, 'quantity' => $qte, 'notes' => 'Historique',
        ]);
    }

    $this->artisan('eggs:repair-stock --force')->assertSuccessful();

    // 8 triés, 8 déjà entrés : rien à reporter. Le stock reste à 3.
    expect((float) $stock->fresh()->current_quantity)->toBe(3.0);
});

test('les PERTES sont reprises aussi', function () {
    // Cassés et anomalies souffraient du même silence.
    collecteTrieeSansStock($this->farm->id, $this->lot->id, ['grade_xl' => 6], casses: 30);

    $this->artisan('eggs:repair-stock --force')->assertSuccessful();

    expect((float) Stock::where('item_name', 'Cassé')->where('category', Stock::CAT_OEUFS)->first()->current_quantity)
        ->toBe(1.0);
});

test('une collecte NON TRIÉE n’entre pas au magasin', function () {
    /*
     * La borne inverse : la réserve brute n'est pas du stock vendable. La
     * reprendre reviendrait à porter au magasin des œufs que personne n'a
     * calibrés.
     *
     * Et ce n'est pas théorique : la COLLECTE enregistre déjà les cassés et les
     * anomalies (RecordEggCollection), bien avant tout tri. Sans le filtre sur
     * `is_graded`, la reprise aurait donc balayé les pertes de toutes les
     * collectes en attente — c'est pourquoi ce cas en porte, plutôt que zéro.
     */
    EggProduction::create([
        'farm_id'              => $this->farm->id,
        'batch_id'             => $this->lot->id,
        'production_date'      => today()->toDateString(),
        'total_eggs_collected' => 221,
        'broken_eggs'          => 30,
        'small_eggs'           => 15,
        'is_graded'            => false,
    ]);

    $this->artisan('eggs:repair-stock --force')->assertSuccessful();

    expect(Stock::where('category', Stock::CAT_OEUFS)->count())->toBe(0);
});
