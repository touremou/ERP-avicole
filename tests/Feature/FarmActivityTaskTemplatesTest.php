<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\ProductionType;
use App\Models\TaskAssignment;
use App\Models\TaskTemplate;
use App\Services\TaskSchedulerService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CATALOGUE D'ACTIVITÉS DE FERME.
 *
 * Le catalogue livré couvrait l'aviculture et sept gestes de cultures. Tout le
 * reste de ce qui se fait sur un site — traite, curage, pisciculture, couvoir,
 * biosécurité, moulin, magasin, ressources, caisse — n'existait dans aucun
 * modèle, donc dans aucun calendrier, donc dans aucun taux de complétion.
 *
 * Deux propriétés doivent tenir, et elles comptent autant que le contenu :
 *   1. les nouveaux modèles sont INACTIFS — les activer d'office ferait
 *      exploser le dénominateur du taux de complétion (S2) et rendrait
 *      l'indicateur inatteignable, donc inutile ;
 *   2. les modèles ciblés par type de lot restent SILENCIEUX tant que la ferme
 *      n'a pas l'atelier correspondant.
 */

beforeEach(function () {
    $this->setUpRbac();
});

test('le catalogue couvre les activités hors aviculture', function () {
    $names = TaskTemplate::withoutGlobalScopes()->pluck('name');

    foreach ([
        'Traite du matin',                              // élevage laitier
        'Curage et raclage de l’étable',
        'Relevé oxygène dissous',                       // pisciculture
        'Retournement des œufs en incubation',          // couvoir
        'Recharge du pédiluve',                         // biosécurité
        'Nettoyage du moulin et du broyeur',            // provenderie
        'Contrôle des dates de péremption',             // magasin
        'Niveau de carburant du groupe',                // ressources
        'Contrôle du réseau d’irrigation',              // cultures
        'Clôture de caisse et comptage',                // commerce
        'Pointage de l’équipe',                         // équipe
    ] as $expected) {
        expect($names)->toContain($expected);
    }
});

test('les nouveaux modèles arrivent INACTIFS', function () {
    // Sans cette garantie, le déploiement ajouterait une trentaine de tâches
    // quotidiennes à chaque ferme et l'indicateur de complétion s'effondrerait
    // sans qu'aucun technicien y soit pour quelque chose.
    $catalogue = ['Traite du matin', 'Relevé oxygène dissous', 'Clôture de caisse et comptage', 'Recharge du pédiluve'];

    foreach ($catalogue as $name) {
        expect(TaskTemplate::withoutGlobalScopes()->where('name', $name)->value('is_active'))
            ->toBeFalsy("Le modèle « {$name} » est actif : il générerait des tâches sans qu'on l'ait demandé.");
    }
});

test('les modèles historiques restent actifs — aucune régression', function () {
    // Le lot ne doit rien éteindre de ce qui tournait.
    foreach (['Alimentation matin', 'Relevé mortalité', 'Arrosage/Irrigation'] as $name) {
        expect(TaskTemplate::withoutGlobalScopes()->where('name', $name)->value('is_active'))->toBeTruthy();
    }
});

test('un modèle ciblé par type de lot reste silencieux sans l’atelier', function () {
    // Une ferme de volaille qui active « Traite du matin » par curiosité ne doit
    // pas voir apparaître de tâche : le filtre batch_types la protège.
    $template = TaskTemplate::withoutGlobalScopes()->where('name', 'Traite du matin')->first();
    $template->update(['is_active' => true]);

    $building = Building::factory()->create(['farm_id' => $this->farm->id]);
    $chair = ProductionType::where('slug', 'chair')->value('id');
    Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $building->id,
        'production_type_id' => $chair, 'status' => 'Actif', 'current_quantity' => 100,
    ]);

    app(TaskSchedulerService::class)->generateForDate(now(), $this->farm->id);

    expect(TaskAssignment::where('task_template_id', $template->id)->count())->toBe(0);
});

test('le même modèle génère bien la tâche quand l’atelier existe', function () {
    // Pendant du test précédent : le silence ne doit pas être un silence total.
    $template = TaskTemplate::withoutGlobalScopes()->where('name', 'Traite du matin')->first();
    $template->update(['is_active' => true]);

    $building = Building::factory()->create(['farm_id' => $this->farm->id]);
    $laitiere = ProductionType::where('slug', 'laitiere')->value('id');
    expect($laitiere)->not->toBeNull('Le type de production « laitiere » doit exister au référentiel.');

    Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $building->id,
        'production_type_id' => $laitiere, 'status' => 'Actif', 'current_quantity' => 12,
    ]);

    app(TaskSchedulerService::class)->generateForDate(now(), $this->farm->id);

    $task = TaskAssignment::where('task_template_id', $template->id)->first();
    expect($task)->not->toBeNull();
    // La preuve exigée descend du modèle : « voici le relevé », pas « j'ai coché ».
    expect($task->proof_type)->toBe('valeur');
    expect($task->proof_unit)->toBe('L');
});

test('les mesures exigent une preuve chiffrée, les gestes une photo', function () {
    // C'est la différence entre « pertinent » et « traçable » — la demande
    // portait sur les deux.
    $measures = ['Relevé oxygène dissous', 'Niveau de carburant du groupe', 'Clôture de caisse et comptage'];
    foreach ($measures as $name) {
        $template = TaskTemplate::withoutGlobalScopes()->where('name', $name)->first();
        expect($template->proof_type)->toBe('valeur');
        expect($template->proof_unit)->not->toBeEmpty("« {$name} » exige une valeur sans dire dans quelle unité.");
    }

    foreach (['Recharge du pédiluve', 'Nettoyage du moulin et du broyeur'] as $name) {
        expect(TaskTemplate::withoutGlobalScopes()->where('name', $name)->value('proof_type'))->toBe('photo');
    }
});

test('rejouer la migration ne duplique aucun modèle', function () {
    $before = TaskTemplate::withoutGlobalScopes()->count();

    $migration = require database_path('migrations/2026_07_31_000000_seed_farm_activity_task_templates.php');
    $migration->up();

    expect(TaskTemplate::withoutGlobalScopes()->count())->toBe($before);
});

test('l’écran de gestion sépare les actifs du catalogue à activer', function () {
    // 36 modèles inactifs mélangés aux actifs rendaient la page illisible.
    $response = $this->actingAs($this->adminUser)
        ->get(route('tasks.templates', ['vue' => 'catalogue']))
        ->assertOk();

    $response->assertSee('Catalogue à activer', false);
    $response->assertSee('Traite du matin', false);

    // Sens INVERSE : la vue « actifs » n'affiche pas le catalogue. On teste dans
    // ce sens-là seulement, car les modèles actifs apparaissent aussi ailleurs
    // sur la page (tâches du jour déjà générées) — chercher leur absence
    // testerait le reste de l'écran, pas le filtre.
    $this->actingAs($this->adminUser)
        ->get(route('tasks.templates', ['vue' => 'actifs']))
        ->assertOk()
        ->assertSee('Alimentation matin', false)
        ->assertDontSee('Traite du matin', false);
});
