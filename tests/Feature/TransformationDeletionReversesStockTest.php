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
 * SUPPRIMER UN LOT DE TRANSFORMATION NE REVERSAIT RIEN.
 *
 * Les deux entités sœurs du même module le font depuis un audit précédent :
 * `CropInputObserver::deleted` reverse l'entrée d'intrant, et
 * `HarvestObserver::deleted` celle de récolte — « sinon le stock dérivait /
 * double-comptait à chaque modification (bug audité, symétrique des
 * récoltes) ». La transformation était la seule des trois à ÉCRIRE le stock à
 * la création sans jamais le défaire.
 *
 * ─── MESURÉ SUR LE CODE D'AVANT ───
 *
 * 200 kg de manioc transformés en 60 kg de gari, puis le lot supprimé :
 *
 *   • manioc ..... reste à 300 kg   (les 200 consommés ne reviennent pas)
 *   • gari ....... reste à  60 kg   (produit fini SANS lot de production)
 *   • récolte .... redevient « non engagée », donc re-transformable
 *
 * Les 60 kg de gari sont le point dur : ils sont comptés dans la valeur de
 * l'inventaire, apparaissent au vendeur comme disponibles, et ne se rattachent
 * à AUCUN lot — donc à aucune date de production, aucune péremption, aucune
 * traçabilité amont. Invendables de bonne foi.
 *
 * ─── ET LES DEUX DÉFAUTS SE COMPOSENT ───
 *
 * La récolte redevenant transformable, supprimer puis re-transformer empile
 * 60 kg de gari à chaque tour — indéfiniment, sans consommer un kilo de plus.
 * La suppression rouvrait par derrière la porte que l'enregistrement venait de
 * fermer.
 *
 * ─── CE QU'ON N'A PAS FAIT ───
 *
 * Bloquer la suppression. Corriger un lot saisi de travers est un geste
 * d'atelier normal, et la récolte DOIT redevenir transformable. Ce qui
 * manquait, c'est que le stock revienne au même instant dans son état d'avant.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $plot = Plot::create([
        'farm_id' => $this->farm->id, 'name' => 'Parcelle Atelier',
        'area_ha' => 1, 'status' => 'libre',
    ]);

    $this->cycle = CropCycle::create([
        'farm_id' => $this->farm->id, 'plot_id' => $plot->id, 'code' => 'CYC-ATL',
        'crop_name' => 'Manioc', 'planting_date' => now()->subMonths(5)->toDateString(),
        'area_used_ha' => 1, 'status' => CropCycle::STATUS_RECOLTE,
        'total_acquisition_cost' => 400_000, 'additional_costs' => 0,
    ]);

    $this->recolte = Harvest::create([
        'farm_id' => $this->farm->id, 'crop_cycle_id' => $this->cycle->id,
        'harvest_date' => now()->subDays(5)->toDateString(),
        'quantity' => 200, 'unit' => 'kg', 'net_weight_kg' => 200,
        'destination' => Harvest::DEST_TRANSFORMATION,
    ]);

    // 500 kg de matière première descendus à l'atelier.
    Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Manioc frais',
        'category' => Stock::CAT_RECOLTES, 'current_quantity' => 500, 'unit' => 'kg',
        'unit_price' => 1_000, 'alert_threshold' => 0,
    ]);
});

/** Quantité en stock d'un article, 0 s'il n'existe pas. */
function stockDe(string $nom, string $categorie): float
{
    return (float) (Stock::where('item_name', $nom)->where('category', $categorie)->value('current_quantity') ?? 0);
}

/** Le formulaire d'atelier : 200 kg de manioc → 60 kg de gari, stock branché. */
function transformerAvecStock(?int $harvestId)
{
    return test()->post(route('crop-transformations.store'), [
        'harvest_id' => $harvestId,
        'input_product' => 'Manioc frais',
        'output_product' => 'Gari',
        'transformation_type' => array_key_first(CropTransformation::TYPES),
        'input_quantity' => 200, 'input_unit' => 'kg',
        'output_quantity' => 60, 'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
        'consumed_from_stock' => 1, 'input_stock_item' => 'Manioc frais',
        'synced_to_stock' => 1, 'output_stock_item' => 'Gari',
    ]);
}

test('la création déstocke la matière et entre le produit fini', function () {
    // L'état de référence : sans lui, les tests de reversement ne mesurent rien.
    transformerAvecStock($this->recolte->id);

    expect(stockDe('Manioc frais', Stock::CAT_RECOLTES))->toBe(300.0)
        ->and(stockDe('Gari', Stock::CAT_PRODUITS_FINIS))->toBe(60.0);
});

test('la suppression REND la matière première consommée', function () {
    transformerAvecStock($this->recolte->id);

    $this->delete(route('crop-transformations.destroy', CropTransformation::first()));

    expect(stockDe('Manioc frais', Stock::CAT_RECOLTES))->toBe(500.0);
});

test('la suppression RETIRE le produit fini devenu orphelin', function () {
    /*
     * Le point dur : 60 kg de gari rattachés à aucun lot de production — donc
     * à aucune date, aucune péremption, aucune traçabilité amont — et pourtant
     * comptés dans la valeur de l'inventaire et proposés à la vente.
     */
    transformerAvecStock($this->recolte->id);

    $this->delete(route('crop-transformations.destroy', CropTransformation::first()));

    expect(stockDe('Gari', Stock::CAT_PRODUITS_FINIS))->toBe(0.0);
});

test('supprimer puis re-transformer n’empile plus de produit fini', function () {
    /*
     * LES DEUX DÉFAUTS COMPOSÉS. La récolte redevient « non engagée » à la
     * suppression — c'est voulu — mais avant, le gari du premier tour restait
     * en stock. Trois tours produisaient 180 kg de gari avec 200 kg de manioc.
     */
    transformerAvecStock($this->recolte->id);
    $this->delete(route('crop-transformations.destroy', CropTransformation::first()));

    transformerAvecStock($this->recolte->id);
    $this->delete(route('crop-transformations.destroy', CropTransformation::first()));

    transformerAvecStock($this->recolte->id);

    expect(stockDe('Gari', Stock::CAT_PRODUITS_FINIS))->toBe(60.0)
        ->and(stockDe('Manioc frais', Stock::CAT_RECOLTES))->toBe(300.0);
});

test('la récolte redevient transformable après suppression', function () {
    // On ne bloque pas la correction d'atelier : c'était le geste légitime que
    // la garde de l'enregistrement ne devait pas emporter avec elle.
    transformerAvecStock($this->recolte->id);
    $this->delete(route('crop-transformations.destroy', CropTransformation::first()));

    expect($this->recolte->fresh()->isEngaged())->toBeFalse();

    transformerAvecStock($this->recolte->id)->assertRedirect();

    expect(CropTransformation::count())->toBe(1);
});

test('un lot NON branché au stock ne fabrique pas de mouvement à sa suppression', function () {
    /*
     * Les deux drapeaux commandent : un lot saisi sans intégration stock ne
     * doit RIEN reverser. Sans cette mesure, un reversement inconditionnel
     * créerait de la matière à chaque suppression — le défaut inverse.
     */
    $this->post(route('crop-transformations.store'), [
        'harvest_id' => $this->recolte->id,
        'input_product' => 'Manioc frais',
        'output_product' => 'Gari',
        'transformation_type' => array_key_first(CropTransformation::TYPES),
        'input_quantity' => 200, 'input_unit' => 'kg',
        'output_quantity' => 60, 'output_unit' => 'kg',
        'production_date' => now()->toDateString(),
    ]);

    $this->delete(route('crop-transformations.destroy', CropTransformation::first()));

    expect(stockDe('Manioc frais', Stock::CAT_RECOLTES))->toBe(500.0)
        ->and(stockDe('Gari', Stock::CAT_PRODUITS_FINIS))->toBe(0.0);
});

test('le retrait du produit fini se plafonne à zéro si le gari est déjà parti', function () {
    /*
     * Choix assumé, aligné sur les reversements sœurs : si une partie du
     * produit fini est déjà vendue, on retire ce qui reste plutôt que de
     * refuser la correction. Refuser laisserait l'opérateur devant un lot faux
     * qu'il ne peut pas effacer — et le pousserait à le contourner.
     */
    transformerAvecStock($this->recolte->id);

    // 50 des 60 kg partent à la vente.
    Stock::where('item_name', 'Gari')->update(['current_quantity' => 10]);

    $this->delete(route('crop-transformations.destroy', CropTransformation::first()));

    expect(stockDe('Gari', Stock::CAT_PRODUITS_FINIS))->toBe(0.0);
});
