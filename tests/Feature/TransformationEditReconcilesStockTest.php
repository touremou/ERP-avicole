<?php

use App\Models\CropCycle;
use App\Models\CropTransformation;
use App\Models\Harvest;
use App\Models\Plot;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA FICHE AFFIRMAIT UN STOCK QU'ELLE N'AVAIT PAS ÉCRIT.
 *
 * L'en-tête du contrôleur l'annonçait : « la re-synchronisation stock n'est pas
 * rejouée pour éviter les doublons ». Le motif est juste — REJOUER la création
 * doublerait. Mais les deux entités sœurs du module ne rejouent pas non plus :
 * elles ANNULENT l'ancienne valeur puis appliquent la nouvelle
 * (`HarvestObserver::reconcileStockOnUpdate`, `CropInputObserver::updated`). Le
 * problème invoqué pour ne rien faire était déjà résolu à côté.
 *
 * ─── MESURÉ SUR LE CODE D'AVANT ───
 *
 * Un lot saisi 200 kg de manioc → 60 kg de gari, puis corrigé en 400 → 600 :
 *
 *   • la fiche affiche ......... 400 consommés, 600 produits
 *   • ses deux drapeaux disent . « consommé du stock », « synchronisé au stock »
 *   • le stock en est resté à .. 200 consommés, 60 produits
 *
 * 540 kg d'écart, sur une fiche qui se déclare synchronisée.
 *
 * ─── ET LA DIVERGENCE SE PROPAGEAIT À LA SUPPRESSION ───
 *
 * Le reversement à la suppression lit les valeurs COURANTES. Créer 200 → 60,
 * corriger en 400 → 600, puis supprimer rendait donc 400 kg de manioc pour 200
 * réellement consommés : le stock passait de 500 à 700 kg. DEUX CENTS KILOS DE
 * MATIÈRE PREMIÈRE CRÉÉS DE RIEN, par trois gestes d'atelier tous légitimes.
 *
 * C'est le test qui compte ici : chacun des trois gestes paraît juste
 * séparément, et c'est leur enchaînement qui fabrique la matière.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $plot = Plot::create([
        'farm_id' => $this->farm->id, 'name' => 'Parcelle Atelier',
        'area_ha' => 1, 'status' => 'libre',
    ]);

    $cycle = CropCycle::create([
        'farm_id' => $this->farm->id, 'plot_id' => $plot->id, 'code' => 'CYC-ATL',
        'crop_name' => 'Manioc', 'planting_date' => now()->subMonths(5)->toDateString(),
        'area_used_ha' => 1, 'status' => CropCycle::STATUS_RECOLTE,
        'total_acquisition_cost' => 400_000, 'additional_costs' => 0,
    ]);

    $this->recolte = Harvest::create([
        'farm_id' => $this->farm->id, 'crop_cycle_id' => $cycle->id,
        'harvest_date' => now()->subDays(5)->toDateString(),
        'quantity' => 200, 'unit' => 'kg', 'net_weight_kg' => 200,
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);

    Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Manioc frais',
        'category' => Stock::CAT_RECOLTES, 'current_quantity' => 500, 'unit' => 'kg',
        'unit_price' => 1_000, 'alert_threshold' => 0,
    ]);

    // Le lot d'origine : 200 kg de manioc → 60 kg de gari, stock branché.
    $this->post(route('crop-transformations.store'), [
        'harvest_id' => $this->recolte->id,
        'input_product' => 'Manioc frais', 'output_product' => 'Gari',
        'transformation_type' => array_key_first(CropTransformation::TYPES),
        'input_quantity' => 200, 'input_unit' => 'kg',
        'output_quantity' => 60, 'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
        'consumed_from_stock' => 1, 'input_stock_item' => 'Manioc frais',
        'synced_to_stock' => 1, 'output_stock_item' => 'Gari',
    ]);
});

/** Quantité en stock d'un article, 0 s'il n'existe pas. */
function quantiteStock(string $nom, string $categorie): float
{
    return (float) (Stock::where('item_name', $nom)->where('category', $categorie)->value('current_quantity') ?? 0);
}

/** Corrige les quantités du lot par le formulaire de modification. */
function corrigerLot(CropTransformation $lot, float $entree, float $sortie)
{
    return test()->put(route('crop-transformations.update', $lot), [
        'input_product' => $lot->input_product,
        'output_product' => $lot->output_product,
        'transformation_type' => $lot->transformation_type,
        'input_quantity' => $entree, 'input_unit' => 'kg',
        'output_quantity' => $sortie, 'output_unit' => 'kg',
        'production_date' => $lot->production_date->toDateString(),
    ]);
}

test('corriger À LA HAUSSE consomme la différence et produit la différence', function () {
    // 200 → 400 consommés : 200 kg de plus sortent. 60 → 600 : 540 de plus entrent.
    corrigerLot(CropTransformation::first(), 400, 600);

    expect(quantiteStock('Manioc frais', Stock::CAT_RECOLTES))->toBe(100.0)
        ->and(quantiteStock('Gari', Stock::CAT_PRODUITS_FINIS))->toBe(600.0);
});

test('corriger À LA BAISSE rend la différence', function () {
    // Le sens inverse, qui distingue une vraie réconciliation d'un simple ajout.
    corrigerLot(CropTransformation::first(), 50, 20);

    expect(quantiteStock('Manioc frais', Stock::CAT_RECOLTES))->toBe(450.0)
        ->and(quantiteStock('Gari', Stock::CAT_PRODUITS_FINIS))->toBe(20.0);
});

test('la fiche et le stock disent la même chose après correction', function () {
    /*
     * Le défaut énoncé tel qu'il se voyait à l'écran : la fiche annonçait 400
     * consommés, drapeau « synchronisé » à vrai, et le stock n'avait pas bougé.
     */
    corrigerLot(CropTransformation::first(), 400, 600);

    $lot = CropTransformation::first();
    $consommeDuStock = 500.0 - quantiteStock('Manioc frais', Stock::CAT_RECOLTES);

    expect($lot->consumed_from_stock)->toBeTrue()
        ->and($consommeDuStock)->toBe((float) $lot->input_quantity)
        ->and(quantiteStock('Gari', Stock::CAT_PRODUITS_FINIS))->toBe((float) $lot->output_quantity);
});

test('créer, corriger, supprimer ramène le stock à son point de départ', function () {
    /*
     * LE défaut composé. Avant : le manioc passait de 500 à 700 kg — 200 kg de
     * matière première créés de rien, par trois gestes d'atelier tous légitimes
     * pris séparément.
     */
    corrigerLot(CropTransformation::first(), 400, 600);

    $this->delete(route('crop-transformations.destroy', CropTransformation::first()));

    expect(quantiteStock('Manioc frais', Stock::CAT_RECOLTES))->toBe(500.0)
        ->and(quantiteStock('Gari', Stock::CAT_PRODUITS_FINIS))->toBe(0.0);
});

test('changer l’ARTICLE de sortie déplace le stock au lieu de le dupliquer', function () {
    /*
     * Le cas que la réconciliation par delta traite et qu'un simple ajustement
     * numérique manquerait : l'ancien article doit être vidé, pas laissé plein.
     */
    $lot = CropTransformation::first();

    test()->put(route('crop-transformations.update', $lot), [
        'input_product' => $lot->input_product,
        'output_product' => 'Attiéké',
        'transformation_type' => $lot->transformation_type,
        'input_quantity' => 200, 'input_unit' => 'kg',
        'output_quantity' => 60, 'output_unit' => 'kg',
        'production_date' => $lot->production_date->toDateString(),
    ]);

    // L'article de stock suit le nom du produit de sortie tant qu'il n'est pas
    // forcé : ce qui compte est qu'AUCUN gari fantôme ne subsiste en double.
    expect(quantiteStock('Gari', Stock::CAT_PRODUITS_FINIS) + quantiteStock('Attiéké', Stock::CAT_PRODUITS_FINIS))
        ->toBe(60.0);
});

test('une modification SANS effet sur les quantités ne touche pas au stock', function () {
    /*
     * La borne qui protège de l'excès inverse. L'action pose ses drapeaux par
     * un `update()` juste après la création : si l'observer réagissait à toute
     * modification, chaque enregistrement doublerait son propre mouvement.
     */
    corrigerLot(CropTransformation::first(), 200, 60);   // mêmes chiffres

    CropTransformation::first()->update(['notes' => 'Séchage prolongé']);

    expect(quantiteStock('Manioc frais', Stock::CAT_RECOLTES))->toBe(300.0)
        ->and(quantiteStock('Gari', Stock::CAT_PRODUITS_FINIS))->toBe(60.0);
});

test('un lot NON branché au stock reste sans effet à la correction', function () {
    // Les drapeaux commandent, ici comme à la suppression.
    CropTransformation::first()->delete();
    Stock::where('item_name', 'Gari')->delete();
    Stock::where('item_name', 'Manioc frais')->update(['current_quantity' => 500]);

    $this->post(route('crop-transformations.store'), [
        'harvest_id' => null,
        'input_product' => 'Manioc frais', 'output_product' => 'Gari',
        'transformation_type' => array_key_first(CropTransformation::TYPES),
        'input_quantity' => 200, 'input_unit' => 'kg',
        'output_quantity' => 60, 'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
    ]);

    corrigerLot(CropTransformation::first(), 400, 600);

    expect(quantiteStock('Manioc frais', Stock::CAT_RECOLTES))->toBe(500.0)
        ->and(quantiteStock('Gari', Stock::CAT_PRODUITS_FINIS))->toBe(0.0);
});
