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

/*
 * LE RENDEMENT SUIT LE NOMBRE DE PLANTS.
 *
 * « À la manière de la date, que le rendement se recalcule quand on modifie le
 * nombre de rejets. » Le calcul direct est plants × poids moyen — mais il
 * suppose UN fruit par pied : vrai d'un ananas, faux d'un manioc, absurde d'un
 * manguier. D'où une colonne explicite au catalogue plutôt qu'une hypothèse
 * silencieuse.
 *
 * Et les deux bases ne s'accordent pas : 55 000 rejets × 1,5 kg = 82 500 kg,
 * quand 1,57 ha × 32 t/ha = 50 200 kg. L'écart révèle une incohérence du
 * RÉFÉRENTIEL, pas un bug — le formulaire l'affiche au lieu de trancher en
 * silence.
 */

test('le rendement se dérive du nombre de plants quand le rapport est connu', function () {
    $ananas = CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas',
        'planting_material' => 'rejet', 'planting_unit' => 'unité', 'planting_density' => 35000,
        'avg_unit_weight_kg' => 1.5, 'harvest_unit_label' => 'fruit',
        'harvest_units_per_plant' => 1,
    ]);

    // 55 000 rejets × 1 fruit × 1,5 kg
    expect($ananas->yieldFromPlantCount(55000))->toBe(82500.0);
});

test('sans rapport unités/pied, aucune dérivation — on ne suppose pas', function () {
    // Un manioc donne plusieurs tubercules, un manguier des centaines de fruits.
    // Supposer « un par pied » produirait un chiffre faux avec l'autorité d'un
    // chiffre calculé.
    $manioc = CropSpecies::create([
        'type' => 'tubercule', 'name' => 'Manioc',
        'planting_material' => 'bouture', 'planting_unit' => 'unité',
        'avg_unit_weight_kg' => 2.0, 'harvest_unit_label' => 'tubercule',
        'harvest_units_per_plant' => null,
    ]);

    expect($manioc->yieldFromPlantCount(10000))->toBeNull();
});

test('une plantation pesée en kilos ne compte pas des pieds', function () {
    $mais = CropSpecies::create([
        'type' => 'cereale', 'name' => 'Maïs',
        'planting_material' => 'semence', 'planting_unit' => 'kg',
        'avg_unit_weight_kg' => 0.25, 'harvest_unit_label' => 'épi',
        'harvest_units_per_plant' => 1,
    ]);

    // 25 kg de semences ne font pas 25 pieds : le modèle calcule, mais le
    // formulaire refuse cette base (planting_unit = kg) — vérifié côté script.
    $script = file_get_contents(resource_path('views/cultures/cycles/partials/form-script.blade.php'));
    expect($script)->toContain("planting_unit === 'kg'");
    expect($mais->yieldFromPlantCount(25))->toBe(6.25); // le modèle reste neutre
});

test('modifier le nombre de plants déclenche le recalcul du rendement', function () {
    $script = file_get_contents(resource_path('views/cultures/cycles/partials/form-script.blade.php'));

    // Le déclencheur demandé, « à la manière de la date ».
    expect($script)->toContain("\$watch('seedQuantity', () => this.recomputeYield())");
    // Et il respecte la même règle : une saisie humaine n'est pas écrasée.
    expect($script)->toContain('isOurs(this.expectedYield, this.autoYield)');
});

test('les deux bases de calcul sont distinctes et le comptage prime', function () {
    $script = file_get_contents(resource_path('views/cultures/cycles/partials/form-script.blade.php'));

    expect($script)->toContain('yieldFromCount()');
    expect($script)->toContain('yieldFromArea()');
    // Le comptage est ce que le producteur maîtrise et vient de saisir.
    expect($script)->toContain('fromCount !== null ? fromCount : this.yieldFromArea()');
});

test('un écart entre les deux bases est SIGNALÉ, pas arbitré en silence', function () {
    $script = file_get_contents(resource_path('views/cultures/cycles/partials/form-script.blade.php'));

    expect($script)->toContain('Écart avec la référence agronomique');
    // Seuil : alerter pour 5 % apprendrait à ignorer l'alerte.
    expect($script)->toContain('gap > 0.15');
});

test('le catalogue accepte et enregistre les unités par pied', function () {
    $this->actingAs($this->adminUser)
        ->post(route('crop-catalogue.store'), [
            'type' => 'fruitier', 'name' => 'Ananas Cayenne',
            'avg_unit_weight_kg' => 1.5, 'harvest_unit_label' => 'fruit',
            'harvest_units_per_plant' => 1,
        ])
        ->assertRedirect();

    expect(CropSpecies::where('name', 'Ananas Cayenne')->first()->harvest_units_per_plant)->toBe(1);
});

test('les DEUX écrans de cycle reçoivent les unités par pied', function () {
    seedAnanas()->update(['avg_unit_weight_kg' => 1.5, 'harvest_unit_label' => 'fruit', 'harvest_units_per_plant' => 1]);

    $plot = Plot::create([
        'farm_id' => $this->farm->id, 'name' => 'P', 'code' => 'P-UP',
        'area_ha' => 2, 'status' => Plot::STATUS_EN_CULTURE,
    ]);
    $cycle = CropCycle::create([
        'farm_id' => $this->farm->id, 'plot_id' => $plot->id, 'code' => 'ANA-UP',
        'crop_name' => 'Ananas', 'area_used_ha' => 1,
        'planting_date' => '2024-12-01', 'status' => CropCycle::STATUS_EN_COURS,
    ]);

    foreach ([route('crop-cycles.create'), route('crop-cycles.edit', $cycle)] as $url) {
        expect($this->actingAs($this->adminUser)->get($url)->assertOk()->getContent())
            ->toContain('harvest_units_per_plant');
    }
});
