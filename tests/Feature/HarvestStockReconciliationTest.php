<?php

use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\Plot;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CORRIGER UNE RÉCOLTE DOIT CORRIGER LE STOCK — et rien ne le vérifiait.
 *
 * Ce lot n'apporte AUCUNE correction : le mécanisme fonctionne. Il apporte la
 * couverture qui manquait, et il faut dire pourquoi c'est autre chose qu'un
 * remplissage.
 *
 * Une récolte synchronisée entre en stock (catégorie « recoltes »), valorisée au coût
 * de production. La corriger — 500 kg pesés à la hâte, ramenés à 300 après pesée
 * réelle — doit annuler l'ancienne entrée et appliquer la nouvelle. C'est
 * HarvestObserver qui s'en charge, et il le fait bien : vérifié de bout en bout,
 * 500 → 300 donne exactement 300.
 *
 * Mais AUCUN test ne le couvrait. Or cet audit a montré à répétition ce qu'il advient
 * des mécanismes que rien ne protège :
 *
 *   • une commande planifiée morte depuis des semaines (14 juillet) ;
 *   • une synchro nocturne qui remettait en stock les œufs vendus (#215) ;
 *   • un résumé quotidien qui n'était jamais parti (#216) ;
 *   • une purge d'audit qui s'annulait en silence (#230).
 *
 * Chacun de ces mécanismes avait été écrit avec soin, et chacun a cessé de
 * fonctionner sans que personne ne s'en aperçoive. Un chemin qui déplace de l'argent
 * en stock, branché sur un observateur — donc invisible à la lecture du contrôleur —
 * est précisément celui qu'on casse un jour par une modification voisine.
 */

beforeEach(function () {
    $this->setUpRbac();

    $parcelle = Plot::create([
        'farm_id' => $this->farm->id, 'code' => 'P1', 'name' => 'Parcelle 1', 'area_ha' => 2,
    ]);

    $this->cycle = CropCycle::create([
        'farm_id'        => $this->farm->id,
        'plot_id'        => $parcelle->id,
        'code'           => 'CY-1',
        'crop_name'      => 'Maïs',
        'planting_date'  => now()->subDays(90)->toDateString(),
        'area_used_ha'   => 1,
        'status'         => CropCycle::STATUS_EN_COURS,
    ]);
});

/** Récolte synchronisée en stock, du poids voulu. */
function recolte(CropCycle $cycle, float $kg, string $article = 'Maïs grain'): Harvest
{
    app(\App\Actions\Crop\RecordHarvest::class)->execute($cycle, [
        'harvest_date'     => now()->toDateString(),
        'quantity'         => $kg,
        'unit'             => 'kg',
        'destination'      => 'vente',
        'sync_to_stock'    => true,
        'stock_item_name'  => $article,
    ]);

    return Harvest::withoutGlobalScopes()->latest('id')->first();
}

/** Niveau de stock d'un article de récolte. */
function stockRecolte(string $article): float
{
    return (float) Stock::withoutGlobalScopes()
        ->where('item_name', $article)
        ->where('category', Stock::CAT_RECOLTES)
        ->value('current_quantity');
}

test('une récolte synchronisée entre en stock', function () {
    recolte($this->cycle, 500);

    expect(stockRecolte('Maïs grain'))->toBe(500.0);
});

test('corriger une récolte À LA BAISSE corrige le stock', function () {
    // Le cas réel : 500 kg estimés au champ, 300 après pesée au magasin.
    $h = recolte($this->cycle, 500);

    $h->update(['quantity' => 300, 'net_weight_kg' => 300]);

    expect(stockRecolte('Maïs grain'))->toBe(300.0);
});

test('corriger une récolte À LA HAUSSE corrige le stock', function () {
    $h = recolte($this->cycle, 300);

    $h->update(['quantity' => 800, 'net_weight_kg' => 800]);

    expect(stockRecolte('Maïs grain'))->toBe(800.0);
});

test('changer l’ARTICLE déplace le stock, il ne le duplique pas', function () {
    // Le cas qui se trompe le plus facilement : sans annulation de l'ancienne
    // entrée, la récolte existerait en double, sur deux articles.
    $h = recolte($this->cycle, 500, 'Maïs grain');

    $h->update(['stock_item_name' => 'Maïs épis']);

    expect(stockRecolte('Maïs grain'))->toBe(0.0)
        ->and(stockRecolte('Maïs épis'))->toBe(500.0);
});

test('supprimer une récolte annule son entrée en stock', function () {
    $h = recolte($this->cycle, 500);

    $h->delete();

    expect(stockRecolte('Maïs grain'))->toBe(0.0);
});

test('une modification SANS effet sur les quantités ne touche pas le stock', function () {
    // Corriger une note ne doit pas produire deux mouvements (annulation +
    // réapplication) : le registre deviendrait illisible, et l'audit des écarts
    // de stock avec.
    $h = recolte($this->cycle, 500);

    $mouvementsAvant = \App\Models\StockMovement::withoutGlobalScopes()->count();

    $h->update(['notes' => 'Pesée confirmée par le magasinier']);

    expect(stockRecolte('Maïs grain'))->toBe(500.0)
        ->and(\App\Models\StockMovement::withoutGlobalScopes()->count())->toBe($mouvementsAvant);
});

test('une récolte NON synchronisée ne touche jamais le stock', function () {
    // Une récolte vendue bord-champ n'entre pas au magasin : la corriger ne doit
    // pas y créer une entrée fantôme.
    app(\App\Actions\Crop\RecordHarvest::class)->execute($this->cycle, [
        'harvest_date'    => now()->toDateString(),
        'quantity'        => 400,
        'unit'            => 'kg',
        'destination'     => 'vente',
        'sync_to_stock'   => false,
        'stock_item_name' => 'Maïs grain',
    ]);

    $h = Harvest::withoutGlobalScopes()->latest('id')->first();
    $h->update(['quantity' => 250, 'net_weight_kg' => 250]);

    expect(stockRecolte('Maïs grain'))->toBe(0.0);
});

test('la réconciliation passe par l’OBSERVATEUR, pas par le contrôleur', function () {
    /*
     * Ce qui rend ce chemin fragile, et justifie de le tenir par des tests : la
     * correction du stock est invisible à la lecture du contrôleur — `updateHarvest`
     * fait un simple `$harvest->update()`. Tout se joue dans HarvestObserver, donc
     * hors du champ de vision de qui modifie l'écran.
     *
     * Si l'observateur cessait d'être enregistré, aucun écran ne changerait
     * d'apparence : le stock se mettrait simplement à mentir.
     */
    $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($provider)->toContain('Harvest::observe');
});
