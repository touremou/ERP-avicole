<?php

use App\Models\CropRecipe;
use App\Models\CropTransformation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN RENDEMENT DE TRANSFORMATION SE JUGE CONTRE CE QUE SA RECETTE ATTEND.
 *
 * La liste des transformations jugeait tout produit à 50 % : un séchage de
 * mangue à 12 % — normal — sortait en alerte ; un décorticage à 60 % quand la
 * recette en attend 70 sortait en vert. La recette porte son rendement attendu.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

function transformationSelon(int $ferme, string $recette, ?float $attendu, float $entree, float $sortie): CropTransformation
{
    $r = CropRecipe::create(['farm_id' => $ferme, 'name' => $recette, 'transformation_type' => 'sechage',
        'output_product' => $recette, 'expected_yield_percent' => $attendu]);

    return CropTransformation::create(['farm_id' => $ferme, 'crop_recipe_id' => $r->id, 'input_product' => 'Brut',
        'output_product' => $recette, 'transformation_type' => 'sechage', 'input_quantity' => $entree,
        'output_quantity' => $sortie, 'yield_percent' => round($sortie / $entree * 100, 2),
        'production_date' => today()->toDateString(), 'status' => 'termine']);
}

test('un séchage à 12 % pour 12 % attendus est bon ; un décorticage à 60 % pour 70 % est faible', function () {
    $sechage = transformationSelon($this->farm->id, 'Mangue séchée', 12, 100, 12);
    $decorticage = transformationSelon($this->farm->id, 'Riz décortiqué', 70, 100, 60);
    $sansAttendu = transformationSelon($this->farm->id, 'Divers', null, 100, 40);

    expect($sechage->fresh()->niveau_rendement)->toBe('bon')
        ->and($decorticage->fresh()->niveau_rendement)->toBe('faible')
        ->and($sansAttendu->fresh()->niveau_rendement)->toBeNull();
});

test('la liste colore chaque rendement contre sa recette', function () {
    transformationSelon($this->farm->id, 'Mangue séchée', 12, 100, 12);

    $html = $this->get(route('crop-transformations.index'))->assertOk()->getContent();
    expect($html)->toMatch('/bg-green-50 text-green-600[^>]*>\s*12,0%/');
});
