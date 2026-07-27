<?php

use App\Actions\Incubation\StartIncubation;
use App\Models\Batch;
use App\Models\Building;
use App\Models\Incubator;
use App\Models\ProductionType;
use App\Models\Species;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DURÉE D'INCUBATION — UNE seule source.
 *
 * Le nombre existait en TROIS endroits qui pouvaient se contredire :
 *   1. un tableau PHP codé en dur dans IncubationController::index() ;
 *   2. le repli « 21 » de StartIncubation, qui ignorait ce tableau — une mise en
 *      couvoir de canards sans durée saisie datait l'éclosion à 21 jours au lieu
 *      de 28, soit une SEMAINE d'écart sur le mirage et le retournement ;
 *   3. le réglage `couvoir.incubation_days`, lu par la seule barre de progression.
 *
 * Et la ferme ne pouvait rien corriger : un canard de Barbarie incube 35 jours,
 * pas 28. Toute espèce ajoutée par l'utilisateur retombait muettement sur la
 * poule.
 */

beforeEach(function () {
    $this->setUpRbac();
});

function hatchingBatch(int $farmId, string $slug, ?int $incubationDays): Batch
{
    $species = Species::firstOrCreate(
        ['slug' => $slug],
        ['name_fr' => ucfirst($slug), 'is_active' => true, 'tracks_eggs' => true]
    );
    $species->update(['incubation_days' => $incubationDays]);

    $building = Building::factory()->create(['farm_id' => $farmId]);

    return Batch::factory()->create([
        'farm_id' => $farmId, 'building_id' => $building->id,
        'species_id' => $species->id,
        'production_type_id' => ProductionType::resolveOrCreate('reproducteur', $species->id)->id,
        'status' => 'Actif', 'current_quantity' => 50,
    ]);
}

test('la durée vient de l’ESPÈCE quand elle n’est pas saisie', function () {
    // Le cas qui datait l'éclosion une semaine trop tôt.
    $batch = hatchingBatch($this->farm->id, 'canard', 28);
    $incubator = Incubator::create(['farm_id' => $this->farm->id, 'name' => 'Couveuse test', 'capacity' => 500, 'status' => 'Disponible']);

    $incubation = app(StartIncubation::class)->execute([
        'incubator_id' => $incubator->id,
        'source_type'  => 'internal',
        'batch_id'     => $batch->id,
        'start_date'   => now()->toDateString(),
        'eggs_count'   => 100,
    ]);

    expect($incubation->incubation_duration)->toBe(28);
    expect($incubation->hatch_date_expected->toDateString())
        ->toBe(now()->addDays(28)->toDateString());
});

test('une durée SAISIE l’emporte sur le référentiel', function () {
    // L'opérateur qui connaît sa souche garde le dernier mot.
    $batch = hatchingBatch($this->farm->id, 'canard', 28);
    $incubator = Incubator::create(['farm_id' => $this->farm->id, 'name' => 'Couveuse test', 'capacity' => 500, 'status' => 'Disponible']);

    $incubation = app(StartIncubation::class)->execute([
        'incubator_id' => $incubator->id,
        'source_type'  => 'internal',
        'batch_id'     => $batch->id,
        'start_date'   => now()->toDateString(),
        'eggs_count'   => 100,
        'duration'     => 35, // canard de Barbarie
    ]);

    expect($incubation->incubation_duration)->toBe(35);
});

test('sans durée à l’espèce, on retombe sur le RÉGLAGE de la ferme', function () {
    // Une espèce ajoutée par l'utilisateur ne doit pas être muette : elle suit le
    // réglage, que la ferme peut fixer — et non une constante enfouie.
    \App\Models\Setting::set('couvoir.incubation_days', 26);
    $batch = hatchingBatch($this->farm->id, 'pintade-locale', null);
    $incubator = Incubator::create(['farm_id' => $this->farm->id, 'name' => 'Couveuse test', 'capacity' => 500, 'status' => 'Disponible']);

    $incubation = app(StartIncubation::class)->execute([
        'incubator_id' => $incubator->id,
        'source_type'  => 'internal',
        'batch_id'     => $batch->id,
        'start_date'   => now()->toDateString(),
        'eggs_count'   => 60,
    ]);

    expect($incubation->incubation_duration)->toBe(26);
});

test('l’accesseur de l’espèce porte la cascade complète', function () {
    \App\Models\Setting::set('couvoir.incubation_days', 24);

    $canard = Species::firstOrCreate(['slug' => 'canard'], ['name_fr' => 'Canard', 'is_active' => true]);
    $canard->update(['incubation_days' => 28]);
    expect($canard->incubationDays())->toBe(28);

    $canard->update(['incubation_days' => null]);
    expect($canard->fresh()->incubationDays())->toBe(24);
});

test('les durées descendent du référentiel, plus d’un tableau codé en dur', function () {
    Species::firstOrCreate(['slug' => 'poulet'], ['name_fr' => 'Poulet', 'is_active' => true])
        ->update(['incubation_days' => 21]);
    Species::firstOrCreate(['slug' => 'canard'], ['name_fr' => 'Canard', 'is_active' => true])
        ->update(['incubation_days' => 28]);

    $durations = Species::incubationDurations();

    expect($durations)->toHaveKey('poulet');
    expect($durations['poulet'])->toBe(21);
    expect($durations['canard'])->toBe(28);

    // Le contrôleur ne doit plus porter la table.
    $controller = file_get_contents(app_path('Http/Controllers/IncubationController.php'));
    expect($controller)->toContain('Species::incubationDurations()');
    expect($controller)->not->toContain("'pintade' => 28");
});

test('la ferme peut corriger la durée d’une espèce', function () {
    // Sans cette route, le référentiel restait en lecture seule et un canard de
    // Barbarie (35 j) était indistinguable d'un canard commun (28 j).
    $species = Species::firstOrCreate(['slug' => 'canard'], ['name_fr' => 'Canard', 'is_active' => true, 'tracks_eggs' => true]);

    $this->actingAs($this->adminUser)
        ->patch(route('admin.species.incubation', $species), ['incubation_days' => 35])
        ->assertRedirect();

    expect($species->fresh()->incubation_days)->toBe(35);
});

test('vider la durée est un choix explicite, pas une erreur', function () {
    $species = Species::firstOrCreate(['slug' => 'canard'], ['name_fr' => 'Canard', 'is_active' => true, 'tracks_eggs' => true]);
    $species->update(['incubation_days' => 35]);

    $this->actingAs($this->adminUser)
        ->patch(route('admin.species.incubation', $species), ['incubation_days' => null])
        ->assertRedirect();

    expect($species->fresh()->incubation_days)->toBeNull();
});

test('une durée hors bornes physiques est refusée', function () {
    $species = Species::firstOrCreate(['slug' => 'canard'], ['name_fr' => 'Canard', 'is_active' => true, 'tracks_eggs' => true]);

    // 90 jours n'existe chez aucun oiseau : c'est une faute de frappe.
    $this->actingAs($this->adminUser)
        ->patch(route('admin.species.incubation', $species), ['incubation_days' => 90])
        ->assertSessionHasErrors('incubation_days');
});

test('un lecteur ne peut PAS corriger le référentiel', function () {
    $species = Species::firstOrCreate(['slug' => 'canard'], ['name_fr' => 'Canard', 'is_active' => true, 'tracks_eggs' => true]);
    $species->update(['incubation_days' => 28]);

    // L'application intercepte le 403 et redirige : ce qui compte est que la
    // valeur du référentiel ne bouge pas.
    $this->actingAs($this->readonlyUser)
        ->patch(route('admin.species.incubation', $species), ['incubation_days' => 35]);

    expect($species->fresh()->incubation_days)->toBe(28);
});

test('le code mort divergent a bien disparu', function () {
    // BatchService n'était appelé par personne et calculait la fin de cycle avec
    // un « chair ? 45 : 540 » codé en dur, en contradiction avec
    // Batch::calculateExpectedEndDate qui lit le référentiel. Un piège pour le
    // prochain lecteur.
    expect(file_exists(app_path('Services/BatchService.php')))->toBeFalse();

    // Et les deux classes fantômes en commentaire, qui faisaient croire à un
    // doublon de règle.
    foreach ([
        app_path('Actions/Incubation/StartIncubation.php'),
        app_path('Http/Requests/Incubation/StartIncubationRequest.php'),
    ] as $path) {
        $source = file_get_contents($path);
        expect(substr_count($source, 'namespace '))->toBe(1);
    }
});
