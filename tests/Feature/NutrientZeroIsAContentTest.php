<?php

use App\Models\FoodNorm;
use App\Models\Formula;
use App\Models\RawMaterial;
use Database\Seeders\ReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « NON ANALYSÉ » SUR LES SIX LIGNES D'UNE FORMULE.
 *
 * Signalé par l'exploitation, capture à l'appui. Une teneur à 0 valait « non
 * analysé » : le sel, les coquilles, le phosphate — qui n'apportent RÉELLEMENT
 * ni énergie ni protéine — rendaient « non analysée » toute formule qui en
 * contient. Une ration volaille ordinaire (maïs, soja, coquilles, sel) ne
 * pouvait donc jamais être confrontée à sa norme, même avec le référentiel
 * livré entièrement renseigné.
 *
 * Désormais : VIDE = non analysé ; 0 = analysé, n'en contient pas.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);
});

/** Le référentiel livré, rattaché à la ferme du test. */
function semerLeReferentiel(int $fermeId): void
{
    test()->seed(ReferentialSeeder::class);
    DB::table('raw_materials')->update(['farm_id' => $fermeId]);
}

/** La matière livrée par le référentiel, par son nom. */
function matiereLivree(string $nom): RawMaterial
{
    return RawMaterial::where('name', $nom)->firstOrFail();
}

/** Une ration volaille ordinaire, sur les matières du référentiel livré. */
function rationVolaille(): Formula
{
    $formule = Formula::create([
        'name' => 'Ration chair', 'code' => strtoupper(uniqid('R')),
        'target_type' => 'chair', 'total_batch_weight' => 1000, 'is_active' => true,
    ]);

    foreach ([['Maïs jaune', 60], ['Tourteau de soja', 30], ['Coquilles / Calcaire', 8], ['Sel', 2]] as [$nom, $part]) {
        $formule->items()->create([
            'raw_material_id' => matiereLivree($nom)->id, 'percentage' => $part, 'quantity_kg' => $part * 10,
        ]);
    }

    return $formule->load('items.rawMaterial');
}

test('une ration ordinaire, sur le référentiel livré, est enfin confrontée à sa norme', function () {
    semerLeReferentiel($this->farm->id);
    FoodNorm::updateOrCreate(['animal_type' => 'chair', 'phase' => 'Démarrage'], [
        'name' => 'CHAIR', 'target_em' => 3000, 'target_pb' => 22, 'target_lys' => 1.30,
        'target_meth' => 0.50, 'target_ca' => 1.00, 'target_p' => 0.70, 'is_active' => true,
    ]);

    $analyse = rationVolaille()->nutritionalComparison();

    // Le sel et les coquilles n'apportent ni énergie, ni protéine, ni lysine :
    // ce 0 est une teneur, pas une lacune.
    // Méthionine et phosphore viennent des valeurs de référence livrées
    // (cf. ReferenceNutrientValuesTest) : les six lignes sont confrontées.
    foreach (['em', 'pb', 'lys', 'meth', 'ca', 'p'] as $nutriment) {
        expect($analyse[$nutriment]['complete'])->toBeTrue("{$nutriment} devrait être analysé")
            ->and($analyse[$nutriment]['ratio'])->not->toBeNull();
    }
});

test('la fiche NOMME les ingrédients à renseigner et mène au laboratoire', function () {
    semerLeReferentiel($this->farm->id);
    $formule = rationVolaille();

    // Un ingrédient de la ferme, jamais analysé.
    $maison = RawMaterial::create([
        'name' => 'Prémélange maison', 'unit' => 'kg', 'stock_qty' => 0, 'unit_cost' => 1000,
        'alert_threshold' => 10, 'is_active' => true,
    ]);
    $formule->items()->create(['raw_material_id' => $maison->id, 'percentage' => 1, 'quantity_kg' => 10]);

    $page = $this->get(route('formulas.show', $formule))->assertOk()->getContent();

    expect($page)->toContain('Teneurs à renseigner pour :')
        ->and($page)->toContain('Prémélange maison')
        ->and($page)->toContain(route('raw-materials.index'));
});

test('au laboratoire, un 0 saisi est une teneur, un champ vide reste « non analysé »', function () {
    $sel = RawMaterial::create([
        'name' => 'Sel de mer', 'unit' => 'kg', 'stock_qty' => 0, 'unit_cost' => 500,
        'alert_threshold' => 10, 'is_active' => true,
    ]);

    $this->put(route('raw-materials.nutrition', $sel), [
        'energy_kcal' => 0, 'protein_rate' => 0, 'lysine_rate' => 0,
        'calcium_rate' => 0, 'methionine_rate' => '', 'phosphorus_rate' => '',
    ])->assertSessionHasNoErrors();

    $sel->refresh();

    expect((float) $sel->energy_kcal)->toBe(0.0)
        ->and($sel->methionine_rate)->toBeNull()
        ->and($sel->phosphorus_rate)->toBeNull();
});

test('la reprise des données : un 0 ancien redevient « non analysé », sauf les vrais zéros livrés', function () {
    semerLeReferentiel($this->farm->id);

    // Un ingrédient de la ferme, saisi avant ce correctif : son 0 voulait dire
    // « pas saisi ». Celui du sel livré est réel.
    $maison = RawMaterial::create([
        'name' => 'Prémélange maison', 'unit' => 'kg', 'stock_qty' => 0, 'unit_cost' => 1000,
        'alert_threshold' => 10, 'is_active' => true,
    ]);
    DB::table('raw_materials')->whereIn('id', [$maison->id, matiereLivree('Sel')->id])
        ->update(['energy_kcal' => 0, 'methionine_rate' => 0]);

    (require database_path('migrations/2026_10_12_000001_nutrient_zero_is_not_unanalysed.php'))->up();

    expect($maison->fresh()->energy_kcal)->toBeNull()
        ->and((float) matiereLivree('Sel')->energy_kcal)->toBe(0.0)
        // La méthionine n'était pas livrée à l'époque de cette reprise : son 0
        // n'y était réel nulle part (2026_10_13 la remplit ensuite).
        ->and(matiereLivree('Sel')->methionine_rate)->toBeNull();
});
