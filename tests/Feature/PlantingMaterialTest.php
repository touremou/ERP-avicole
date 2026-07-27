<?php

use App\Models\CropSpecies;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * MATÉRIEL DE PLANTATION — le formulaire doit suivre la culture.
 *
 * Le formulaire de cycle demandait « Quantité semence » en « kg » pour TOUTE
 * culture. On ne plante pas un ananas en kilos de semence : on plante des
 * REJETS, comptés à l'unité. Idem manioc (boutures), banane (rejets), tomate
 * (plants de pépinière). Le technicien devait convertir mentalement ou laisser
 * le champ vide — et le coût de plantation devenait incomparable d'un cycle à
 * l'autre.
 */

beforeEach(function () {
    $this->setUpRbac();
});

test('une espèce à multiplication végétative se compte à l’unité', function () {
    $ananas = CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas',
        'planting_material' => 'rejet', 'planting_unit' => 'unité', 'planting_density' => 35000,
    ]);

    expect($ananas->planting_label)->toBe('Nombre de rejets (unité)');
    expect($ananas->planting_label)->not->toContain('kg');
});

test('une céréale garde bien la semence en kilos', function () {
    $mais = CropSpecies::create([
        'type' => 'cereale', 'name' => 'Maïs',
        'planting_material' => 'semence', 'planting_unit' => 'kg', 'planting_density' => 25,
    ]);

    expect($mais->planting_label)->toBe('Quantité de semences (kg)');
});

test('une espèce sans référence garde le comportement historique', function () {
    // Non-régression : une culture hors catalogue ne doit pas casser l'écran.
    $inconnue = CropSpecies::create(['type' => 'autre', 'name' => 'Culture rare']);

    expect($inconnue->planting_label)->toBe('Quantité de semences (kg)');
    expect($inconnue->suggestedPlantingQuantity(1.0))->toBeNull();
});

test('la quantité suggérée suit la surface', function () {
    $ananas = CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas',
        'planting_material' => 'rejet', 'planting_unit' => 'unité', 'planting_density' => 35000,
    ]);

    expect($ananas->suggestedPlantingQuantity(0.5))->toBe(17500.0);
    // Un demi-rejet n'existe pas : ce qui se compte s'arrondit.
    expect($ananas->suggestedPlantingQuantity(0.333))->toBe(11655.0);
});

test('en kilos, la suggestion garde deux décimales', function () {
    $mais = CropSpecies::create([
        'type' => 'cereale', 'name' => 'Maïs',
        'planting_material' => 'semence', 'planting_unit' => 'kg', 'planting_density' => 25,
    ]);

    expect($mais->suggestedPlantingQuantity(0.75))->toBe(18.75);
});

test('sans surface, aucune suggestion — on ne devine pas', function () {
    $ananas = CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas',
        'planting_material' => 'rejet', 'planting_unit' => 'unité', 'planting_density' => 35000,
    ]);

    expect($ananas->suggestedPlantingQuantity(null))->toBeNull();
    expect($ananas->suggestedPlantingQuantity(0))->toBeNull();
});

test('la migration renseigne les espèces DÉJÀ au catalogue', function () {
    // Sans ce rattrapage, la fonctionnalité n'existerait que pour les espèces
    // créées après la mise à jour — et le technicien continuerait à voir
    // « semence en kg » sur son ananas.
    DB::table('crop_species')->insert([
        ['name' => 'Ananas', 'type' => 'fruitier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
         'planting_material' => null, 'planting_unit' => null, 'planting_density' => null],
        ['name' => 'Manioc', 'type' => 'tubercule', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
         'planting_material' => null, 'planting_unit' => null, 'planting_density' => null],
        // Accent et casse différents : la correspondance doit tenir.
        ['name' => 'mais', 'type' => 'cereale', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
         'planting_material' => null, 'planting_unit' => null, 'planting_density' => null],
    ]);

    $migration = require database_path('migrations/2026_08_01_000000_add_planting_material_to_crop_species.php');
    (new ReflectionClass($migration))->getMethod('backfill')->invoke($migration);

    expect(CropSpecies::where('name', 'Ananas')->first()->planting_material)->toBe('rejet');
    expect(CropSpecies::where('name', 'Ananas')->first()->planting_unit)->toBe('unité');
    expect(CropSpecies::where('name', 'Manioc')->first()->planting_material)->toBe('bouture');
    // « mais » sans accent doit être reconnu comme « Maïs ».
    expect(CropSpecies::where('name', 'mais')->first()->planting_unit)->toBe('kg');
});

test('une espèce hors liste retombe sur son TYPE, pas sur la semence', function () {
    // Un fruitier inconnu se plante en plants, pas en kilos de semence : sans ce
    // repli par type, on retomberait sur le défaut qu'on corrige.
    DB::table('crop_species')->insert([
        'name' => 'Corossolier', 'type' => 'fruitier', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
        'planting_material' => null, 'planting_unit' => null, 'planting_density' => null,
    ]);

    $migration = require database_path('migrations/2026_08_01_000000_add_planting_material_to_crop_species.php');
    (new ReflectionClass($migration))->getMethod('backfill')->invoke($migration);

    $species = CropSpecies::where('name', 'Corossolier')->first();
    expect($species->planting_material)->toBe('plant');
    expect($species->planting_unit)->toBe('unité');
});

test('le formulaire de cycle reçoit le matériel de plantation', function () {
    CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas', 'is_active' => true,
        'planting_material' => 'rejet', 'planting_unit' => 'unité', 'planting_density' => 35000,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('crop-cycles.create'))
        ->assertOk()
        // Sans ces valeurs dans le catalogue encodé, le champ ne pourrait pas
        // s'adapter côté navigateur.
        ->assertSee('planting_material', false)
        ->assertSee('rejet', false)
        ->assertSee('35000', false);
});

test('le mobile reçoit les MÊMES colonnes que le web', function () {
    // Une divergence entre supports pour la même culture est le pire des cas :
    // « Nombre de rejets » sur l'ordinateur, « semence en kg » sur le téléphone.
    CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas', 'is_active' => true,
        'planting_material' => 'rejet', 'planting_unit' => 'unité', 'planting_density' => 35000,
    ]);

    $response = $this->actingAs($this->adminUser, 'sanctum')
        ->getJson('/api/v1/sync/pull')
        ->assertOk();

    $payload = json_encode($response->json('entities.crop_species'));

    expect($payload)->toContain('planting_material');
    expect($payload)->toContain('rejet');
    expect($payload)->toContain('35000');
});

test('le catalogue accepte et enregistre le matériel de plantation', function () {
    $this->actingAs($this->adminUser)
        ->post(route('crop-catalogue.store'), [
            'type' => 'fruitier', 'name' => 'Ananas Cayenne',
            'planting_material' => 'rejet', 'planting_unit' => 'unité', 'planting_density' => 35000,
        ])
        ->assertRedirect();

    $species = CropSpecies::where('name', 'Ananas Cayenne')->first();
    expect($species)->not->toBeNull();
    expect($species->planting_material)->toBe('rejet');
    expect($species->planting_density)->toBe(35000);
});

/*
 * POIDS MOYEN DE L'UNITÉ RÉCOLTÉE — le pont entre ce qu'on compte et ce qu'on
 * pèse.
 *
 * Le rendement reste un poids : le kilo porte le prix de vente, donc la marge.
 * Mais un producteur d'ananas plante des rejets, vend des fruits et raisonne en
 * fruits — « 50 000 kg » ne lui dit rien tant qu'il ne sait pas que cela fait
 * environ 33 000 fruits. Et au champ, c'est l'inverse qui manque : il COMPTE des
 * fruits alors qu'une récolte conservée exige une pesée en kg (T1).
 */

test('le rendement en kg se convertit en nombre de fruits', function () {
    $ananas = CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas',
        'avg_unit_weight_kg' => 1.5, 'harvest_unit_label' => 'fruit',
    ]);

    expect($ananas->unitsForWeight(50000))->toBe(33333);
    expect($ananas->harvestUnitPlural(33333))->toBe('fruits');
    // Le singulier se voit aussi : « 1 fruit », pas « 1 fruits ».
    expect($ananas->harvestUnitPlural(1))->toBe('fruit');
});

test('un comptage se convertit en poids — l’usage qui sert au champ', function () {
    $ananas = CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas',
        'avg_unit_weight_kg' => 1.5, 'harvest_unit_label' => 'fruit',
    ]);

    // Sans cela, une récolte conservée reste sans pesée, donc sans valeur.
    expect($ananas->weightForUnits(500))->toBe(750.0);
});

test('sans poids moyen, aucune conversion — on ne devine pas un calibre', function () {
    $riz = CropSpecies::create(['type' => 'cereale', 'name' => 'Riz']);

    expect($riz->unitsForWeight(1000))->toBeNull();
    expect($riz->weightForUnits(100))->toBeNull();
    expect($riz->harvestUnitPlural())->toBeNull();
});

test('un poids ou un comptage nul ne produit pas de division absurde', function () {
    $ananas = CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas',
        'avg_unit_weight_kg' => 1.5, 'harvest_unit_label' => 'fruit',
    ]);

    expect($ananas->unitsForWeight(0))->toBeNull();
    expect($ananas->unitsForWeight(null))->toBeNull();
    expect($ananas->weightForUnits(0))->toBeNull();
});

test('le petit calibre survit à l’arrondi', function () {
    // Un gombo pèse 15 g. Avec deux décimales il serait tombé à 0,02 kg, soit
    // 25 % d'erreur ; avec un entier, à zéro — et la conversion n'existerait plus.
    $gombo = CropSpecies::create([
        'type' => 'legume', 'name' => 'Gombo',
        'avg_unit_weight_kg' => 0.015, 'harvest_unit_label' => 'gousse',
    ]);

    expect((float) $gombo->fresh()->avg_unit_weight_kg)->toBe(0.015);
    expect($gombo->unitsForWeight(30))->toBe(2000);
});

test('la migration renseigne le poids des espèces déjà au catalogue', function () {
    DB::table('crop_species')->insert([
        ['name' => 'Ananas', 'type' => 'fruitier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Banane plantain', 'type' => 'fruitier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Riz', 'type' => 'cereale', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration = require database_path('migrations/2026_08_02_000000_add_avg_unit_weight_to_crop_species.php');
    (new ReflectionClass($migration))->getMethod('backfill')->invoke($migration);

    expect((float) CropSpecies::where('name', 'Ananas')->first()->avg_unit_weight_kg)->toBe(1.5);
    // On compte des RÉGIMES de bananes, jamais des doigts.
    expect(CropSpecies::where('name', 'Banane plantain')->first()->harvest_unit_label)->toBe('régime');
    // Le riz reste sans poids : personne ne compte des grains, et inventer une
    // valeur afficherait une équivalence absurde.
    expect(CropSpecies::where('name', 'Riz')->first()->avg_unit_weight_kg)->toBeNull();
});

test('le catalogue encodé des formulaires est UNIQUE et complet', function () {
    // Ce tableau vivait en deux copies : en ajoutant le matériel de plantation je
    // n'avais patché que la création, et l'édition affichait « Quantité semence »
    // sur un ananas. Le test de l'époque cherchait l'appel JavaScript, pas les
    // DONNÉES — il passait au vert sur un écran cassé.
    $species = CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas', 'is_active' => true,
        'planting_material' => 'rejet', 'planting_unit' => 'unité', 'planting_density' => 35000,
        'avg_unit_weight_kg' => 1.5, 'harvest_unit_label' => 'fruit',
    ]);

    $catalogue = CropSpecies::formCatalogue(CropSpecies::with('varieties')->get());

    expect($catalogue[0])->toHaveKeys([
        'name', 'planting_material', 'planting_unit', 'planting_density',
        'avg_unit_weight_kg', 'harvest_unit_label', 'varieties',
    ]);
    expect($catalogue[0]['avg_unit_weight_kg'])->toBe(1.5);
    expect($catalogue[0]['harvest_unit_label'])->toBe('fruit');
});

test('les DEUX écrans de cycle reçoivent le poids moyen', function () {
    CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas', 'is_active' => true,
        'planting_material' => 'rejet', 'planting_unit' => 'unité', 'planting_density' => 35000,
        'avg_unit_weight_kg' => 1.5, 'harvest_unit_label' => 'fruit',
    ]);
    $plot = \App\Models\Plot::create([
        'farm_id' => $this->farm->id, 'name' => 'P', 'code' => 'P-1',
        'area_ha' => 2, 'status' => \App\Models\Plot::STATUS_EN_CULTURE,
    ]);
    $cycle = \App\Models\CropCycle::create([
        'farm_id' => $this->farm->id, 'plot_id' => $plot->id, 'code' => 'ANA-9',
        'crop_name' => 'Ananas', 'area_used_ha' => 1,
        'planting_date' => '2024-12-01', 'status' => \App\Models\CropCycle::STATUS_EN_COURS,
    ]);

    foreach ([route('crop-cycles.create'), route('crop-cycles.edit', $cycle)] as $url) {
        $html = $this->actingAs($this->adminUser)->get($url)->assertOk()->getContent();

        // On vérifie les DONNÉES, pas seulement la présence de la fonction.
        expect($html)->toContain('avg_unit_weight_kg');
        expect($html)->toContain('harvest_unit_label');
        expect($html)->toContain('rejet');
    }
});

test('le mobile reçoit le poids moyen pour proposer la pesée', function () {
    CropSpecies::create([
        'type' => 'fruitier', 'name' => 'Ananas', 'is_active' => true,
        'avg_unit_weight_kg' => 1.5, 'harvest_unit_label' => 'fruit',
    ]);

    $payload = json_encode($this->actingAs($this->adminUser, 'sanctum')
        ->getJson('/api/v1/sync/pull')->assertOk()->json('entities.crop_species'));

    expect($payload)->toContain('avg_unit_weight_kg');
    expect($payload)->toContain('harvest_unit_label');
});

test('le catalogue accepte et enregistre le poids moyen', function () {
    $this->actingAs($this->adminUser)
        ->post(route('crop-catalogue.store'), [
            'type' => 'fruitier', 'name' => 'Ananas Cayenne',
            'avg_unit_weight_kg' => 1.5, 'harvest_unit_label' => 'fruit',
        ])
        ->assertRedirect();

    $species = CropSpecies::where('name', 'Ananas Cayenne')->first();
    expect((float) $species->avg_unit_weight_kg)->toBe(1.5);
    expect($species->harvest_unit_label)->toBe('fruit');
});

test('un poids moyen absurde est refusé', function () {
    $this->actingAs($this->adminUser)
        ->post(route('crop-catalogue.store'), [
            'type' => 'fruitier', 'name' => 'Fruit géant',
            'avg_unit_weight_kg' => 900, // au-delà du plafond : erreur de saisie
        ])
        ->assertSessionHasErrors('avg_unit_weight_kg');
});
