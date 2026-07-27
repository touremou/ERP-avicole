<?php

use App\Models\CropCycle;
use App\Models\CropSpecies;
use App\Models\Plot;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * SUGGESTIONS DU FORMULAIRE DE CYCLE — une seule logique, deux écrans.
 *
 * Deux défauts rapportés du terrain :
 *
 *   1. « la date de récolte se charge au lancement, mais ne fonctionne plus
 *      quand on change de date de début ». Le recalcul ne remplissait que les
 *      champs VIDES : une fois la date posée, changer le semis la laissait
 *      silencieusement fausse.
 *
 *   2. la logique vivait en DEUX COPIES (création, édition). Le correctif
 *      n'aurait donc existé que dans l'une — la même erreur que les formulaires
 *      employé, où une règle dupliquée avait divergé sans que rien ne le signale.
 *
 * Le comportement lui-même est du JavaScript : ces tests verrouillent ce qu'on
 * peut vérifier côté serveur — la logique est UNIQUE, partagée, et porte bien le
 * suivi de « notre » suggestion qui permet le recalcul.
 */

beforeEach(function () {
    $this->setUpRbac();
});

function seedAnanas(): CropSpecies
{
    return CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas', 'is_active' => true,
        'cycle_days_min' => 360, 'cycle_days_max' => 450, 'avg_yield_tha' => 32,
        'planting_material' => 'rejet', 'planting_unit' => 'unité', 'planting_density' => 35000,
    ]);
}

test('la logique de formulaire n’existe qu’à UN seul endroit', function () {
    $partial = file_get_contents(resource_path('views/cultures/cycles/partials/form-script.blade.php'));
    $create = file_get_contents(resource_path('views/cultures/cycles/create.blade.php'));
    $edit = file_get_contents(resource_path('views/cultures/cycles/edit.blade.php'));

    expect(substr_count($partial, 'function cropCycleForm'))->toBe(1);
    // Une seconde définition ferait re-diverger les deux écrans au premier
    // correctif appliqué d'un seul côté.
    expect(substr_count($create, 'function cropCycleForm'))->toBe(0);
    expect(substr_count($edit, 'function cropCycleForm'))->toBe(0);

    expect($create)->toContain("@include('cultures.cycles.partials.form-script')");
    expect($edit)->toContain("@include('cultures.cycles.partials.form-script')");
});

test('le recalcul distingue notre suggestion d’une saisie humaine', function () {
    $partial = file_get_contents(resource_path('views/cultures/cycles/partials/form-script.blade.php'));

    // Le cœur du correctif : sans ce suivi, ou on n'actualise jamais (bug
    // rapporté), ou on écrase la saisie du technicien.
    expect($partial)->toContain('autoHarvest');
    expect($partial)->toContain('isOurs(');

    // Et surtout : le recalcul ne doit PLUS être conditionné au seul champ vide.
    expect($partial)->not->toContain('&& !this.expectedHarvest');
    expect($partial)->not->toContain('&& !this.expectedYield');
});

test('changer la date de semis est bien écouté', function () {
    $partial = file_get_contents(resource_path('views/cultures/cycles/partials/form-script.blade.php'));

    expect($partial)->toContain("\$watch('plantingDate'");
    expect($partial)->toContain("\$watch('areaHa'");
    // La variété change la durée de cycle : elle doit recalculer, pas seulement
    // rafraîchir l'astuce affichée.
    expect($partial)->toContain("\$watch('variety', () => this.recompute())");
});

test('les DEUX écrans reçoivent le catalogue et les valeurs de départ', function () {
    seedAnanas();
    $plot = Plot::create([
        'farm_id' => $this->farm->id, 'name' => 'Parcelle A', 'code' => 'P-A',
        'area_ha' => 2, 'status' => Plot::STATUS_DISPONIBLE,
    ]);
    $cycle = CropCycle::create([
        'farm_id' => $this->farm->id, 'plot_id' => $plot->id, 'code' => 'ANA-1',
        'crop_name' => 'Ananas', 'area_used_ha' => 1.5,
        'planting_date' => '2024-12-01', 'status' => CropCycle::STATUS_EN_COURS,
    ]);

    foreach ([
        route('crop-cycles.create'),
        route('crop-cycles.edit', $cycle),
    ] as $url) {
        $html = $this->actingAs($this->adminUser)->get($url)->assertOk()->getContent();

        expect($html)->toContain('cropCycleForm(');
        expect($html)->toContain('catalogue:');
        expect($html)->toContain('initial:');
        // Le matériel de plantation doit descendre dans les deux écrans, sinon
        // l'édition afficherait « semence » là où la création dit « rejets ».
        expect($html)->toContain('planting_material');
        expect($html)->toContain('rejet');
    }
});

test('l’édition adapte aussi le libellé de plantation', function () {
    seedAnanas();
    $plot = Plot::create([
        'farm_id' => $this->farm->id, 'name' => 'Parcelle B', 'code' => 'P-B',
        'area_ha' => 2, 'status' => Plot::STATUS_EN_CULTURE,
    ]);
    $cycle = CropCycle::create([
        'farm_id' => $this->farm->id, 'plot_id' => $plot->id, 'code' => 'ANA-2',
        'crop_name' => 'Ananas', 'area_used_ha' => 1,
        'planting_date' => '2024-12-01', 'status' => CropCycle::STATUS_EN_COURS,
    ]);

    // Avant, seul l'écran de création portait le libellé adapté : modifier un
    // cycle d'ananas redemandait « Quantité semence ».
    $this->actingAs($this->adminUser)
        ->get(route('crop-cycles.edit', $cycle))
        ->assertOk()
        ->assertSee('plantingLabel()', false)
        ->assertDontSee('Quantité semence', false);
});

test('le rendement reste un POIDS, et l’écran l’explique', function () {
    seedAnanas();

    // Réponse à « le rendement est toujours en KG, est-ce normal ? » : oui, et
    // ce n'est pas une incohérence — c'est le kilo qui rend deux cycles
    // comparables et qui porte le prix de vente. On l'écrit à l'écran.
    $partial = file_get_contents(resource_path('views/cultures/cycles/partials/form-script.blade.php'));
    expect($partial)->toContain('yieldHint()');

    $this->actingAs($this->adminUser)
        ->get(route('crop-cycles.create'))
        ->assertOk()
        ->assertSee('yieldHint()', false)
        ->assertSee('Rendement attendu (kg)', false);
});
