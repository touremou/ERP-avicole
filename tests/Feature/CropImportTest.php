<?php

use App\Models\CropRecipe;
use App\Models\CropRecipeItem;
use App\Models\CropSpecies;
use App\Models\CropVariety;
use Illuminate\Http\UploadedFile;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

// ─── HELPERS ───

function makeCatalogueCsv(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'cat') . '.csv';
    file_put_contents($path, $content);
    return new UploadedFile($path, 'catalogue.csv', 'text/csv', null, true);
}

function makeRecipeCsv(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'rec') . '.csv';
    file_put_contents($path, $content);
    return new UploadedFile($path, 'recipes.csv', 'text/csv', null, true);
}

// ─── CATALOGUE : FORMULAIRE D'IMPORT ───

test('un manager peut accéder au formulaire d\'import du catalogue', function () {
    $this->actingAs($this->managerUser)
        ->get(route('crop-catalogue.import'))
        ->assertStatus(200)
        ->assertSee('Importer');
});

test('un lecteur seul ne peut pas accéder au formulaire d\'import du catalogue', function () {
    $this->actingAs($this->readonlyUser)
        ->get(route('crop-catalogue.import'))
        ->assertRedirect();
});

// ─── CATALOGUE : IMPORT CSV ───

test('un CSV valide crée les espèces et variétés attendues', function () {
    $csv = "type,name,varieties\ncereale,Maïs,Jaune;Hybride\nmaraicher,Tomate,Roma";

    $this->actingAs($this->managerUser)
        ->post(route('crop-catalogue.import.store'), [
            'file' => makeCatalogueCsv($csv),
        ])
        ->assertRedirect(route('crop-catalogue.index'));

    expect(CropSpecies::where('type', 'cereale')->where('name', 'Maïs')->exists())->toBeTrue();

    $mais = CropSpecies::where('name', 'Maïs')->first();
    expect($mais)->not->toBeNull()
        ->and($mais->type)->toBe('cereale')
        ->and($mais->varieties()->count())->toBe(2);

    $tomate = CropSpecies::where('name', 'Tomate')->first();
    expect($tomate->varieties()->count())->toBe(1);
});

test('une espèce existante n\'est pas dupliquée lors d\'un second import', function () {
    $csv = "type,name\ncereale,Maïs";

    // Premier import
    $this->actingAs($this->managerUser)
        ->post(route('crop-catalogue.import.store'), [
            'file' => makeCatalogueCsv($csv),
        ]);

    // Second import identique
    $this->actingAs($this->managerUser)
        ->post(route('crop-catalogue.import.store'), [
            'file' => makeCatalogueCsv($csv),
        ]);

    expect(CropSpecies::where('name', 'Maïs')->count())->toBe(1);
});

test('un type invalide est ignoré lors de l\'import du catalogue', function () {
    $csv = "type,name\ntype_invalide,PlanteMystere";

    $this->actingAs($this->managerUser)
        ->post(route('crop-catalogue.import.store'), [
            'file' => makeCatalogueCsv($csv),
        ])
        ->assertRedirect(route('crop-catalogue.index'));

    expect(CropSpecies::where('name', 'PlanteMystere')->exists())->toBeFalse();
});

test('un lecteur seul ne peut pas importer le catalogue', function () {
    $csv = "type,name\ncereale,Maïs";

    $countBefore = CropSpecies::count();

    $this->actingAs($this->readonlyUser)
        ->post(route('crop-catalogue.import.store'), [
            'file' => makeCatalogueCsv($csv),
        ])
        ->assertRedirect();

    expect(CropSpecies::count())->toBe($countBefore);
});

// ─── RECETTES : FORMULAIRE D'IMPORT ───

test('un manager peut accéder au formulaire d\'import des recettes', function () {
    $this->actingAs($this->managerUser)
        ->get(route('crop-recipes.import'))
        ->assertStatus(200)
        ->assertSee('Importer');
});

test('un lecteur seul ne peut pas accéder au formulaire d\'import des recettes', function () {
    $this->actingAs($this->readonlyUser)
        ->get(route('crop-recipes.import'))
        ->assertRedirect();
});

// ─── RECETTES : IMPORT CSV ───

test('un CSV valide crée les recettes et ingrédients attendus', function () {
    $csv = "name,transformation_type,output_product,description,ingredients\nGari de Manioc,fermentation,Gari,Farine fermentée,Manioc:50:kg;Eau:10:L\nJus de Mangue,jus,Jus mangue,,Mangue:20:kg";

    $this->actingAs($this->managerUser)
        ->post(route('crop-recipes.import.store'), [
            'file' => makeRecipeCsv($csv),
        ])
        ->assertRedirect(route('crop-recipes.index'));

    expect(CropRecipe::withoutFarm()->where('name', 'Gari de Manioc')->exists())->toBeTrue();

    $gari = CropRecipe::withoutFarm()->where('name', 'Gari de Manioc')->first();
    expect($gari)->not->toBeNull()
        ->and($gari->transformation_type)->toBe('fermentation')
        ->and($gari->items()->count())->toBe(2);

    $ingredient = $gari->items()->where('input_product', 'Manioc')->first();
    expect($ingredient)->not->toBeNull()
        ->and((float) $ingredient->quantity)->toBe(50.0)
        ->and($ingredient->unit)->toBe('kg');
});

test('une recette existante n\'est pas dupliquée', function () {
    $csv = "name,transformation_type,output_product,description,ingredients\nGari de Manioc,fermentation,Gari,,";

    $this->actingAs($this->managerUser)
        ->post(route('crop-recipes.import.store'), [
            'file' => makeRecipeCsv($csv),
        ]);

    $this->actingAs($this->managerUser)
        ->post(route('crop-recipes.import.store'), [
            'file' => makeRecipeCsv($csv),
        ]);

    expect(CropRecipe::withoutFarm()->where('name', 'Gari de Manioc')->count())->toBe(1);
});

test('un lecteur seul ne peut pas importer des recettes', function () {
    $csv = "name,transformation_type,output_product,description,ingredients\nGari,fermentation,Gari,,";

    $countBefore = CropRecipe::withoutFarm()->count();

    $this->actingAs($this->readonlyUser)
        ->post(route('crop-recipes.import.store'), [
            'file' => makeRecipeCsv($csv),
        ])
        ->assertRedirect();

    expect(CropRecipe::withoutFarm()->count())->toBe($countBefore);
});
