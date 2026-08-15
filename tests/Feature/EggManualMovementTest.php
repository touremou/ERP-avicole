<?php

use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use App\Models\StockMovement;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE SEUL ÉCRAN QUI RÉÉCRIVAIT LE STOCK D'ŒUFS À LA MAIN ÉCHAPPAIT AUX DEUX
 * RÈGLES DU MAGASIN.
 *
 * `egg-movements.store` est une route vivante : elle accepte un calibre, un
 * type (in / out / adjustment), une quantité et un motif. C'est le geste même
 * qui alerte partout ailleurs depuis #215, étendu à trois autres chemins par
 * #229, #230 et #234. Ici, rien ne partait.
 *
 * La correction ne duplique pas la règle : elle branche l'écran sur le chemin
 * canonique du magasin (MoveStockAction), qui alerte et surveille le
 * franchissement de seuil. Ne reste de propre aux œufs que la conversion
 * Unité → Alvéole.
 *
 * AU PASSAGE, une sortie plus grande que le stock était silencieusement
 * plafonnée à zéro : la matière manquante disparaissait sans erreur. Elle est
 * désormais refusée, comme sur l'écran du magasin.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->calibre = Stock::create([
        'category' => Stock::CAT_OEUFS, 'item_name' => 'M', 'unit' => 'Alvéole',
        'current_quantity' => 100, 'unit_price' => 0, 'last_unit_price' => 0, 'alert_threshold' => 0,
    ]);

    $this->actingAs($this->managerUser);
});

test('un ajustement manuel du stock d’œufs ALERTE', function () {
    // LE défaut : le geste le plus surveillé du magasin ne l'était pas ici.
    $avant = DB::table('notifications')->count();

    $this->post(route('egg-movements.store'), [
        'calibre' => 'M', 'type' => 'adjustment', 'quantity' => 40,
        'unit' => 'alveole', 'reason' => 'Recomptage du casier',
    ])->assertRedirect();

    expect($this->calibre->fresh()->current_quantity)->toEqual(40)
        ->and(DB::table('notifications')->count())->toBeGreaterThan($avant);
});

test('le mouvement reste journalisé au nom de son auteur', function () {
    // Non-régression : l'ancien chemin nommait déjà l'auteur (systemActorId
    // retombe sur Auth::id() avant son repli « premier compte »). Le nouveau
    // doit continuer, et il le fait explicitement.
    $this->post(route('egg-movements.store'), [
        'calibre' => 'M', 'type' => 'adjustment', 'quantity' => 40,
        'unit' => 'alveole', 'reason' => 'Recomptage du casier',
    ])->assertRedirect();

    $mouvement = StockMovement::where('stock_id', $this->calibre->id)->latest('id')->first();

    expect($mouvement->user_id)->toBe($this->managerUser->id);
});

test('une sortie supérieure au stock est REFUSÉE, non plafonnée à zéro', function () {
    // L'ancien chemin ramenait le stock à 0 sans rien dire : la matière
    // manquante disparaissait du registre au lieu d'être signalée.
    $this->post(route('egg-movements.store'), [
        'calibre' => 'M', 'type' => 'out', 'quantity' => 500,
        'unit' => 'alveole', 'reason' => 'Sortie atelier',
    ])->assertSessionHasErrors('quantity');

    expect($this->calibre->fresh()->current_quantity)->toEqual(100);
});

test('la saisie en UNITÉS est convertie en alvéoles', function () {
    // La seule règle propre aux œufs qui reste dans cet écran.
    $parAlveole = (int) setting('general.eggs_per_tray', 30);

    $this->post(route('egg-movements.store'), [
        'calibre' => 'M', 'type' => 'in', 'quantity' => 3 * $parAlveole,
        'unit' => 'unite', 'reason' => 'Retour de tournée',
    ])->assertRedirect();

    expect((float) $this->calibre->fresh()->current_quantity)->toBe(103.0);
});

test('une entrée ordinaire n’alerte pas — seule la réécriture manuelle le fait', function () {
    // On ne rend pas l'écran bruyant : c'est l'ajustement qui est un vecteur de
    // dissimulation, pas la réception.
    $avant = DB::table('notifications')->count();

    $this->post(route('egg-movements.store'), [
        'calibre' => 'M', 'type' => 'in', 'quantity' => 10,
        'unit' => 'alveole', 'reason' => 'Retour de tournée',
    ])->assertRedirect();

    expect(DB::table('notifications')->count())->toBe($avant);
});

test('un calibre inexistant en stock est refusé proprement', function () {
    Stock::where('item_name', 'M')->where('category', Stock::CAT_OEUFS)->delete();

    $this->post(route('egg-movements.store'), [
        'calibre' => 'M', 'type' => 'adjustment', 'quantity' => 10,
        'unit' => 'alveole', 'reason' => 'Recomptage',
    ])->assertRedirect()->assertSessionHas('error');
});

test('le tri MORT a disparu du contrôleur', function () {
    /*
     * Trois méthodes de tri y vivaient sans route, sans vue, et écrivaient dans
     * des colonnes absentes du schéma. Un second exemplaire divergent de la
     * règle de tri, illisible comme tel.
     */
    $source = file_get_contents(app_path('Http/Controllers/EggMovementController.php'));
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $source);

    expect($code)->not->toContain('function storeTri')
        ->and($code)->not->toContain('function updateTri')
        ->and($code)->not->toContain('function showTriForm');
});

test('les colonnes fantômes du tri mort ne sont plus référencées nulle part', function () {
    // Le vrai schéma porte is_graded / grade_s / production_date /
    // total_eggs_collected. Le code mort écrivait les quatre autres noms.
    $fantomes = ['is_sorted', 'qty_anomaly', 'collection_date', 'total_collected', 'sorted_at'];

    $vus = [];
    foreach (glob(app_path('Http/Controllers/*.php')) as $fichier) {
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents($fichier));
        foreach ($fantomes as $colonne) {
            if (str_contains($code, "'{$colonne}'") || str_contains($code, "->{$colonne}")) {
                $vus[] = basename($fichier) . ' : ' . $colonne;
            }
        }
    }

    expect($vus)->toBe([]);
});

test('le chemin canonique du magasin est bien celui employé', function () {
    // La garde de forme : réécrire un stock à la main doit passer par l'outil
    // qui alerte. Un futur écran qui rappellerait StockIntegrationService pour
    // un geste humain rouvrirait exactement le même trou.
    $source = file_get_contents(app_path('Http/Controllers/EggMovementController.php'));
    $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $source);

    expect($code)->toContain('MoveStockAction')
        ->and($code)->not->toContain('StockIntegrationService::syncMovement');
});
