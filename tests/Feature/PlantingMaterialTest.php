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
