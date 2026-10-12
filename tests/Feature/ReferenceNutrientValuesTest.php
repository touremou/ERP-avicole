<?php

use App\Models\Formula;
use App\Models\RawMaterial;
use Database\Seeders\ReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * VALEURS DE RÉFÉRENCE, MARQUÉES COMME TELLES.
 *
 * Décision de l'exploitation : pré-remplir les teneurs des matières premières
 * livrées (méthionine et phosphore compris) avec les valeurs des tables de
 * composition, pour que l'équilibre nutritionnel d'une formule soit confronté
 * à sa norme sans attendre une analyse par ingrédient.
 *
 * Trois garde-fous :
 *   • une estimation se DIT : la fiche signale les formules calculées sur des
 *     valeurs de référence, la matière porte « Valeurs de référence » ;
 *   • une analyse saisie au laboratoire les remplace, et le semeur ne
 *     l'écrase jamais ;
 *   • la reprise ne remplit que ce qui est VIDE.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);
});

/** Le référentiel livré, rattaché à la ferme du test. */
function referentielDeLaFerme(int $fermeId): void
{
    // Le semeur tourne en ligne de commande, sans ferme en session (donc sans
    // portée de ferme) : on le rejoue dans ces mêmes conditions.
    $ferme = session()->pull('current_farm_id');
    test()->seed(ReferentialSeeder::class);
    session(['current_farm_id' => $ferme]);

    DB::table('raw_materials')->update(['farm_id' => $fermeId]);
}

function matiereDeReference(string $nom): RawMaterial
{
    return RawMaterial::where('name', $nom)->firstOrFail();
}

test('les matières livrées portent les six teneurs, marquées « référence »', function () {
    referentielDeLaFerme($this->farm->id);

    $soja = matiereDeReference('Tourteau de soja');

    expect($soja->nutrition_source)->toBe(RawMaterial::SOURCE_REFERENCE)
        ->and((float) $soja->methionine_rate)->toBe(0.64)
        ->and((float) $soja->phosphorus_rate)->toBe(0.62);

    // Aucune matière livrée ne reste sans teneur.
    foreach (array_keys(ReferentialSeeder::COLONNES_DE_LA_TABLE) as $colonne) {
        expect(RawMaterial::whereNull($colonne)->count())->toBe(0, "{$colonne} vide");
    }
});

test('la fiche d’une formule dit qu’elle est calculée sur des estimations', function () {
    referentielDeLaFerme($this->farm->id);

    $formule = Formula::create([
        'name' => 'Ration chair', 'code' => 'RC-REF', 'target_type' => 'chair',
        'total_batch_weight' => 1000, 'is_active' => true,
    ]);
    $formule->items()->create(['raw_material_id' => matiereDeReference('Maïs jaune')->id, 'percentage' => 60, 'quantity_kg' => 600]);
    $formule->items()->create(['raw_material_id' => matiereDeReference('Tourteau de soja')->id, 'percentage' => 40, 'quantity_kg' => 400]);

    $page = $this->get(route('formulas.show', $formule))->assertOk()->getContent();

    expect($page)->toContain('Calcul sur valeurs de référence (estimations) pour :')
        ->and($page)->toContain('Maïs jaune');
});

test('une analyse saisie remplace la référence, et le semeur ne l’écrase plus', function () {
    referentielDeLaFerme($this->farm->id);
    $soja = matiereDeReference('Tourteau de soja');

    $this->put(route('raw-materials.nutrition', $soja->id), [
        'energy_kcal' => 2350, 'protein_rate' => 44, 'lysine_rate' => 2.7,
        'methionine_rate' => 0.60, 'calcium_rate' => 0.28, 'phosphorus_rate' => 0.65,
    ])->assertSessionHasNoErrors();

    expect($soja->fresh()->nutrition_source)->toBe(RawMaterial::SOURCE_SAISIE);

    // Un nouveau passage du semeur (réinstallation, db:seed) respecte l'analyse.
    referentielDeLaFerme($this->farm->id);

    $soja->refresh();
    expect((float) $soja->methionine_rate)->toBe(0.60)
        ->and((float) $soja->protein_rate)->toBe(44.0)
        ->and($soja->nutrition_source)->toBe(RawMaterial::SOURCE_SAISIE);
});

test('la reprise ne remplit que les teneurs VIDES des matières de la table', function () {
    referentielDeLaFerme($this->farm->id);

    // État d'avant : maïs corrigé à la main sur l'énergie, méthionine et
    // phosphore vides ; une matière de la ferme analysée ; une autre jamais.
    DB::table('raw_materials')->update(['nutrition_source' => null]);
    DB::table('raw_materials')->where('name', 'Maïs jaune')
        ->update(['energy_kcal' => 3250, 'methionine_rate' => null, 'phosphorus_rate' => null]);
    $analysee = RawMaterial::create(['name' => 'Tourteau local', 'unit' => 'kg', 'stock_qty' => 0, 'unit_cost' => 1,
        'alert_threshold' => 1, 'is_active' => true, 'energy_kcal' => 2500, 'protein_rate' => 40]);
    $vierge = RawMaterial::create(['name' => 'Inconnue', 'unit' => 'kg', 'stock_qty' => 0, 'unit_cost' => 1,
        'alert_threshold' => 1, 'is_active' => true]);
    DB::table('raw_materials')->whereIn('id', [$analysee->id, $vierge->id])->update(['nutrition_source' => null]);

    (require database_path('migrations/2026_10_13_000001_reference_nutrient_values.php'))->up();

    $mais = matiereDeReference('Maïs jaune');
    expect((float) $mais->energy_kcal)->toBe(3250.0)            // la correction reste
        ->and((float) $mais->methionine_rate)->toBe(0.18)       // le vide est rempli
        ->and($mais->nutrition_source)->toBe(RawMaterial::SOURCE_REFERENCE)
        ->and($analysee->fresh()->nutrition_source)->toBe(RawMaterial::SOURCE_SAISIE)
        ->and($vierge->fresh()->nutrition_source)->toBeNull()
        ->and($vierge->fresh()->methionine_rate)->toBeNull();
});
