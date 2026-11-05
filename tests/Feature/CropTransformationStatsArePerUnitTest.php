<?php

use App\Models\CropTransformation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * TRANSFORMATIONS DE CULTURES : DES CHIFFRES PAR UNITÉ.
 *
 * Recommandation standard retenue par l'exploitation. Le « produit fini
 * (30 j) » additionnait des kilos de farine et des litres d'huile, affichés en
 * kg ; le « rendement moyen » était la moyenne simple de transformations de
 * toutes natures. Désormais : une somme par unité de sortie, et un rendement
 * par conversion, Σ sorties ÷ Σ entrées.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);
});

function transformation(int $ferme, float $entree, string $uniteEntree, float $sortie, string $uniteSortie): void
{
    CropTransformation::create(['farm_id' => $ferme, 'input_product' => 'Brut', 'output_product' => 'Fini',
        'transformation_type' => 'sechage', 'input_quantity' => $entree, 'input_unit' => $uniteEntree,
        'output_quantity' => $sortie, 'output_unit' => $uniteSortie,
        'yield_percent' => round($sortie / $entree * 100, 2),
        'production_date' => today()->toDateString(), 'status' => 'termine']);
}

test('le produit fini se totalise par unité, le rendement par conversion et pondéré', function () {
    transformation($this->farm->id, 1000, 'kg', 700, 'kg');   // grosse mouture : 70 %
    transformation($this->farm->id, 10, 'kg', 4, 'kg');       // essai : 40 %
    transformation($this->farm->id, 100, 'kg', 30, 'L');      // huile : 30 L

    $stats = $this->get(route('crop-transformations.index'))->assertOk()->viewData('stats');

    expect(collect($stats['output_30d'])->pluck('quantite', 'unite')->all())->toEqual(['kg' => 704.0, 'L' => 30.0]);

    $rendements = collect($stats['yield_by_unit'])->pluck('rendement', 'conversion');
    // (700 + 4) / (1 000 + 10) = 69,7 % — la moyenne simple des trois aurait dit 46,7 %.
    expect($rendements['kg → kg'])->toBe(69.7)
        ->and($rendements['kg → L'])->toBe(30.0);
});
