<?php

use App\Models\FoodNorm;
use App\Models\Formula;
use App\Models\RawMaterial;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA NORME NUTRITIONNELLE PILOTE LA FORMULE.
 *
 * Le référentiel `food_norms` fixe SEPT cibles par phase d'alimentation. Avant
 * ce lot :
 *   • la liste comparait au prix cible avec un repli à 4 500 et la fiche
 *     tranchait sur un « coût < 5 000 » codé en dur → deux verdicts opposés sur
 *     la même formule à la même seconde ;
 *   • sans norme rattachée, la fiche affichait 3 000 kcal / 20 % / 1,1 % sous
 *     l'étiquette « Cible (Norme) » — une cible inventée ;
 *   • la pondération du mélange existait en quatre exemplaires, dont un dans le
 *     modèle que personne n'appelait ;
 *   • méthionine et phosphore étaient ciblés sans exister au catalogue des
 *     matières, et la lysine n'était saisissable dans AUCUN formulaire : sa
 *     jauge restait à 0 % en rouge sur toutes les fiches ;
 *   • l'import ajoutait un jeu complet de normes à chaque passage.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Matière première dont on maîtrise chaque teneur. */
function material(string $name, array $values = []): RawMaterial
{
    return RawMaterial::create(array_merge([
        'name' => $name, 'unit' => 'kg', 'stock_qty' => 1000, 'unit_cost' => 3000,
        'alert_threshold' => 100, 'is_active' => true,
        'energy_kcal' => 3300, 'protein_rate' => 9, 'lysine_rate' => 0.25,
        'methionine_rate' => 0.18, 'calcium_rate' => 0.02, 'phosphorus_rate' => 0.28,
    ], $values));
}

function norm(string $animalType, array $values = []): FoodNorm
{
    return FoodNorm::create(array_merge([
        'name' => strtoupper($animalType), 'animal_type' => $animalType, 'phase' => 'Démarrage',
        'target_em' => 3000, 'target_pb' => 22, 'target_lys' => 1.30,
        'target_meth' => 0.50, 'target_ca' => 1.00, 'target_p' => 0.70,
        'target_price_kg' => 5500, 'is_active' => true,
    ], $values));
}

/** Formule à un seul ingrédient : le mélange vaut exactement la matière. */
function formulaOf(string $targetType, RawMaterial $material, float $share = 100): Formula
{
    $formula = Formula::create([
        'name' => 'Test ' . $targetType, 'code' => strtoupper(uniqid('F')),
        'target_type' => $targetType, 'total_batch_weight' => 1000, 'is_active' => true,
    ]);

    $formula->items()->create([
        'raw_material_id' => $material->id, 'percentage' => $share,
        'quantity_kg' => $share * 10,
    ]);

    return $formula->load('items.rawMaterial');
}

test('le verdict économique vient du prix cible du référentiel', function () {
    // Le cas exact qui se contredisait : un aliment d'alevinage à 8 500 GNF/kg
    // pour une cible de 9 500. La fiche le déclarait « À RÉVISER » (coût > 5 000)
    // pendant que la liste le donnait sous la norme.
    norm('silure_alevinage', ['target_price_kg' => 9500]);
    $formula = formulaOf('silure_alevinage', material('Farine de poisson', ['unit_cost' => 8500]));

    $verdict = $formula->economicVerdict();

    expect($verdict['cost'])->toBe(8500.0)
        ->and($verdict['target'])->toBe(9500.0)
        ->and($verdict['status'])->toBe('under');
});

test('un surcoût réel est signalé comme tel', function () {
    norm('chair', ['target_price_kg' => 5500]);
    $formula = formulaOf('chair', material('Maïs cher', ['unit_cost' => 7000]));

    expect($formula->economicVerdict()['status'])->toBe('over')
        ->and($formula->economicVerdict()['diff'])->toBe(1500.0);
});

test('un écart dans le bruit des cours n’est pas un surcoût', function () {
    // 2 % au-dessus de la cible : les cours des matières bougent plus que ça
    // d'une semaine à l'autre. L'ancien seuil binaire peignait ce cas en rouge.
    norm('chair', ['target_price_kg' => 5000]);
    $formula = formulaOf('chair', material('Maïs', ['unit_cost' => 5100]));

    expect($formula->economicVerdict()['status'])->toBe('near');
});

test('SANS norme, aucun verdict n’est rendu', function () {
    // Le point de principe : une absence de référence n'est pas une performance.
    $formula = formulaOf('espece_inconnue', material('Son de blé', ['unit_cost' => 2000]));

    $verdict = $formula->economicVerdict();

    expect($verdict['status'])->toBe('unknown')
        ->and($verdict['target'])->toBeNull()
        ->and($verdict['diff'])->toBeNull();
});

test('SANS norme, aucune cible nutritionnelle n’est inventée', function () {
    $formula = formulaOf('espece_inconnue', material('Son de blé'));

    foreach ($formula->nutritionalComparison() as $key => $row) {
        expect($row['target'])->toBeNull("le nutriment {$key} ne doit pas porter de cible");
        expect($row['ratio'])->toBeNull();
    }
});

test('les six nutriments du référentiel sont confrontés', function () {
    // Méthionine et phosphore étaient ciblés sans exister au catalogue.
    norm('chair');
    $formula = formulaOf('chair', material('Prémélange complet'));

    $comparison = $formula->nutritionalComparison();

    expect(array_keys($comparison))->toBe(['em', 'pb', 'lys', 'meth', 'ca', 'p']);
    foreach ($comparison as $row) {
        expect($row['target'])->not->toBeNull();
    }
});

test('une teneur NON analysée n’est pas prise pour une carence', function () {
    // Le défaut le plus visible : la lysine n'était saisissable nulle part, donc
    // toujours à 0, et la fiche affichait « 0 / 1,30 % » en rouge sur toutes les
    // formules de l'application.
    norm('chair');
    $formula = formulaOf('chair', material('Maïs sans analyse AA', ['lysine_rate' => 0]));

    $comparison = $formula->nutritionalComparison();

    expect($comparison['lys']['complete'])->toBeFalse()
        ->and($comparison['lys']['ratio'])->toBeNull()
        ->and($comparison['lys']['missing'])->toContain('Maïs sans analyse AA');

    // Les nutriments renseignés, eux, restent comparables.
    expect($comparison['pb']['complete'])->toBeTrue()
        ->and($comparison['pb']['ratio'])->not->toBeNull();
});

test('un ingrédient à 0 % ne bloque pas la comparaison', function () {
    // Une ligne laissée vide dans le formulaire ne participe pas au mélange :
    // elle ne doit pas rendre tout le profil incomparable.
    norm('chair');
    $formula = formulaOf('chair', material('Maïs'));
    $formula->items()->create([
        'raw_material_id' => material('Additif non analysé', ['lysine_rate' => 0])->id,
        'percentage' => 0, 'quantity_kg' => 0,
    ]);

    expect($formula->fresh()->load('items.rawMaterial')->nutritionalComparison()['lys']['complete'])->toBeTrue();
});

test('la pondération du mélange est proportionnelle aux parts', function () {
    $formula = formulaOf('chair', material('Maïs', ['energy_kcal' => 3300, 'protein_rate' => 9]), 50);
    $formula->items()->create([
        'raw_material_id' => material('Tourteau de soja', ['energy_kcal' => 2400, 'protein_rate' => 46])->id,
        'percentage' => 50, 'quantity_kg' => 500,
    ]);

    $profile = $formula->fresh()->load('items.rawMaterial')->nutritional_profile;

    expect($profile['em'])->toBe(2850.0)   // (3300 + 2400) / 2
        ->and($profile['pb'])->toBe(27.5); // (9 + 46) / 2
});

test('la résolution en masse désigne la MÊME norme que la résolution unitaire', function () {
    // Deux chemins de résolution, une seule règle : la plus ancienne norme
    // active du type. Sans cela, la liste et la fiche pouvaient afficher deux
    // cibles différentes pour la même formule.
    $first = norm('chair', ['phase' => 'Démarrage']);
    norm('chair', ['phase' => 'Croissance']);
    norm('chair', ['phase' => 'Finition']);

    $formula = formulaOf('chair', material('Maïs'));
    expect($formula->norm()->id)->toBe($first->id);

    $collection = collect([formulaOf('chair', material('Sorgho'))]);
    Formula::attachNorms($collection);
    expect($collection->first()->norm()->id)->toBe($first->id);
});

test('une norme INACTIVE n’est pas retenue', function () {
    norm('chair', ['phase' => 'Démarrage', 'is_active' => false]);
    $active = norm('chair', ['phase' => 'Croissance', 'target_price_kg' => 5300]);

    expect(formulaOf('chair', material('Maïs'))->norm()->id)->toBe($active->id);
});

test('l’ambiguïté du référentiel est exposée, pas arbitrée en silence', function () {
    norm('chair', ['phase' => 'Démarrage']);
    norm('chair', ['phase' => 'Croissance']);

    expect(formulaOf('chair', material('Maïs'))->normCandidates())->toHaveCount(2);
});

test('la fiche n’affiche plus de cible codée en dur', function () {
    norm('silure_alevinage', ['target_em' => 3300, 'target_pb' => 45, 'target_price_kg' => 9500]);
    $formula = formulaOf('silure_alevinage', material('Farine de poisson', [
        'unit_cost' => 8500, 'energy_kcal' => 3300, 'protein_rate' => 45,
    ]));

    $response = $this->actingAs($this->adminUser)->get(route('formulas.show', $formula));

    $response->assertOk()
        ->assertSee('3 300', false)   // la cible du référentiel
        ->assertSee('45,0', false)
        ->assertSee('9 500', false)   // le prix cible, absent auparavant
        ->assertSee('Sous la norme');

    // Et surtout : plus aucune trace du verdict binaire ni des cibles de repli.
    $source = file_get_contents(resource_path('views/provenderie/formulas/show.blade.php'));
    expect($source)->not->toContain('5000')
        ->and($source)->not->toContain('À Réviser');
});

test('la liste et la fiche rendent le MÊME verdict', function () {
    // La contradiction observée : vert dans la liste, « À réviser » sur la fiche.
    norm('silure_alevinage', ['target_price_kg' => 9500]);
    $formula = formulaOf('silure_alevinage', material('Farine de poisson', ['unit_cost' => 8500]));

    $list = $this->actingAs($this->adminUser)->get(route('formulas.index'));
    $sheet = $this->actingAs($this->adminUser)->get(route('formulas.show', $formula));

    $list->assertOk()->assertSee('Sous la norme');
    $sheet->assertOk()->assertSee('Sous la norme');
});

test('l’écran d’optimisation affiche enfin les cibles', function () {
    // On y modifiait une recette sans voir ni teneur, ni cible, ni coût.
    norm('chair', ['name' => 'Poulet de Chair — Démarrage']);
    $formula = formulaOf('chair', material('Maïs'));

    $this->actingAs($this->adminUser)->get(route('formulas.edit', $formula))
        ->assertOk()
        ->assertSee('POULET DE CHAIR — DÉMARRAGE', false)
        ->assertSee('data-lab-norm', false)
        ->assertSee('data-t-meth', false);   // les six cibles, jusqu'à la méthionine
});

test('création et édition partagent le même moteur de calcul', function () {
    // Deux implémentations JS divergentes : Alpine à deux nutriments d'un côté,
    // un simple total des parts de l'autre.
    norm('chair');
    $formula = formulaOf('chair', material('Maïs'));

    foreach ([route('formulas.create'), route('formulas.edit', $formula)] as $url) {
        $this->actingAs($this->adminUser)->get($url)
            ->assertOk()
            ->assertSee('window.FormulaLab', false)
            ->assertSee('data-lab-share', false);
    }
});

test('la lysine est saisissable au laboratoire', function () {
    $material = material('Tourteau de soja', ['lysine_rate' => 0, 'methionine_rate' => 0, 'phosphorus_rate' => 0]);

    $this->actingAs($this->adminUser)
        ->put(route('raw-materials.nutrition', $material->id), [
            'energy_kcal' => 2400, 'protein_rate' => 46,
            'lysine_rate' => 2.85, 'methionine_rate' => 0.65,
            'calcium_rate' => 0.30, 'phosphorus_rate' => 0.65,
        ])->assertRedirect();

    $fresh = $material->fresh();
    expect((float) $fresh->lysine_rate)->toBe(2.85)
        ->and((float) $fresh->methionine_rate)->toBe(0.65)
        ->and((float) $fresh->phosphorus_rate)->toBe(0.65);
});

test('le modèle de fichier du référentiel existe et se réimporte', function () {
    // Le lien proposait « /templates/norms_template.xlsx », absent du dépôt.
    norm('chair', ['name' => 'Poulet de Chair — Démarrage']);

    $response = $this->actingAs($this->adminUser)->get(route('norms.template'));
    $response->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Poulet de Chair — Démarrage')
        ->and($csv)->toContain('chair')
        ->and($csv)->toContain('Méthionine');

    // L'ordre des colonnes doit être celui qu'attend l'import.
    $header = explode(';', str_replace("\xEF\xBB\xBF", '', strtok($csv, "\n")));
    expect(count($header))->toBe(count(FoodNorm::IMPORT_COLUMNS));
});

test('réimporter le référentiel met à jour au lieu de dupliquer', function () {
    norm('chair', ['phase' => 'Démarrage', 'target_em' => 3000, 'target_price_kg' => 5500]);

    $import = new \App\Imports\FoodNormImport;

    // Deux passages du même fichier, la cible ayant été corrigée entre-temps.
    $import->model(['Poulet Chair Démarrage', 'chair', 'Démarrage', 3000, 22, 1.3, 0.5, 1.0, 0.7, 5500]);
    $import->model(['Poulet Chair Démarrage', 'chair', 'Démarrage', 3050, 22, 1.3, 0.5, 1.0, 0.7, 5400]);

    $norms = FoodNorm::where('animal_type', 'chair')->get();

    expect($norms)->toHaveCount(1)
        ->and((float) $norms->first()->target_em)->toBe(3050.0)
        ->and((float) $norms->first()->target_price_kg)->toBe(5400.0);
});

test('l’import tolère la virgule décimale et refuse les lignes vides', function () {
    $import = new \App\Imports\FoodNormImport;

    // Un Excel francophone écrit « 3 000,50 » — que (float) tronquait à 3.
    $import->model(['Ponte', 'ponte', 'Ponte', '2 750,50', '17,25', '0,80', '0,38', '3,80', '0,45', '5 000']);
    $import->model(['', '', '', '', '', '', '', '', '', '']);          // ligne vide
    $import->model(['Titre du classeur', 'x', 'y', 'pas un nombre', '', '', '', '', '', '']);

    $norms = FoodNorm::all();

    expect($norms)->toHaveCount(1)
        ->and((float) $norms->first()->target_em)->toBe(2750.5)
        ->and((float) $norms->first()->target_pb)->toBe(17.25);
});

test('la base refuse deux normes pour la même phase', function () {
    norm('chair', ['phase' => 'Démarrage']);

    expect(fn () => norm('chair', ['phase' => 'Démarrage']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('la liste ne requête pas le référentiel formule par formule', function () {
    norm('chair');
    foreach (range(1, 5) as $i) {
        formulaOf('chair', material("Matière {$i}"));
    }

    $formulas = Formula::with('items.rawMaterial')->get();

    DB::enableQueryLog();
    Formula::attachNorms($formulas);
    foreach ($formulas as $formula) {
        $formula->norm();
    }
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1);
});

test('aucun écran ne recalcule sa propre pondération', function () {
    // La somme pondérée existait en quatre exemplaires. Une seule doit rester :
    // celle du modèle.
    foreach ([
        'views/provenderie/formulas/index.blade.php',
        'views/provenderie/formulas/show.blade.php',
    ] as $view) {
        $source = file_get_contents(resource_path($view));
        expect($source)->not->toContain('energy_kcal ?? 0')
            ->and($source)->not->toContain('protein_rate ?? 0');
    }
});
