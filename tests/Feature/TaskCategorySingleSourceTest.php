<?php

use App\Models\TaskTemplate;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES CATÉGORIES DE TÂCHES NE SE LIMITENT PAS À L'ÉLEVAGE.
 *
 * Signalé depuis le terrain : créer une tâche « Arrosage / Irrigation » n'offrait
 * que six catégories — Alimentation, Collecte, Contrôle, Nettoyage, Santé,
 * Maintenance. Toutes d'élevage. Un arrosage se rangeait donc sous
 * « ALIMENTATION », et le planificateur devenait illisible.
 *
 * La liste vivait en CINQ exemplaires, tous différents :
 *
 *   • TaskTemplate::getCategoryLabelAttribute()   12 catégories ;
 *   • le tableau $catMeta du catalogue            14 ;
 *   • les QUATRE <select> des formulaires          6 — l'élevage seulement ;
 *   • la validation du contrôleur                 « string|max:50 », donc tout ;
 *   • la carte catégorie → service                 6.
 *
 * Le catalogue affichait donc correctement des modèles agricoles (les 36 ajoutés
 * pour couvrir les activités de la ferme) qu'AUCUN formulaire ne permettait de
 * créer ni de modifier.
 */

beforeEach(function () {
    $this->setUpRbac();
});

test('le référentiel couvre l’élevage, les cultures ET les relevés', function () {
    $slugs = array_keys(TaskTemplate::CATEGORIES);

    // Les six d'élevage, préservées.
    foreach (['alimentation', 'collecte', 'controle', 'nettoyage', 'sante', 'maintenance'] as $slug) {
        expect($slugs)->toContain($slug);
    }

    // Les cultures — celles qui manquaient aux formulaires.
    foreach (['semis', 'irrigation', 'sarclage', 'fertilisation', 'traitement', 'recolte'] as $slug) {
        expect($slugs)->toContain($slug);
    }

    // Et les relevés de compteurs, utilisés par les modèles livrés.
    foreach (['releve_eau', 'releve_energie'] as $slug) {
        expect($slugs)->toContain($slug);
    }
});

test('toute catégorie utilisée par un modèle livré est proposée au formulaire', function () {
    // Le cœur du défaut : des modèles existaient dans des catégories que le menu
    // n'offrait pas. Ce test attrape la réapparition du décalage.
    $used = TaskTemplate::withoutGlobalScopes()->distinct()->pluck('category')->filter();
    $offered = array_keys(TaskTemplate::CATEGORIES);

    foreach ($used as $category) {
        expect($offered)->toContain($category);
    }
});

test('le menu déroulant propose l’irrigation — le cas signalé', function () {
    $response = $this->actingAs($this->adminUser)->get(route('tasks.templates'))->assertOk();

    $response->assertSee('value="irrigation"', false)
        ->assertSee('value="semis"', false)
        ->assertSee('value="releve_eau"', false)
        // Et les groupes qui gardent les quatorze options lisibles.
        ->assertSee('Cultures')
        ->assertSee('Relevés');
});

test('les QUATRE écrans proposent la même liste', function () {
    $template = TaskTemplate::create([
        'name' => 'Arrosage/Irrigation', 'category' => 'irrigation',
        'frequency' => 'quotidien', 'estimated_minutes' => 60,
        'priority' => 'haute', 'is_active' => true,
    ]);

    $urls = [
        route('tasks.templates'),                       // création d'un modèle
        route('tasks.templates.edit', $template),        // édition d'un modèle
        route('tasks.index'),                            // filtre + création rapide
    ];

    foreach ($urls as $url) {
        $this->actingAs($this->adminUser)->get($url)
            ->assertOk()
            ->assertSee('value="irrigation"', false);
    }
});

test('une tâche d’irrigation peut enfin être créée', function () {
    // Avant, le menu ne l'offrait pas : l'opérateur choisissait « alimentation ».
    $this->actingAs($this->adminUser)
        ->post(route('tasks.store'), [
            'title'          => 'Arrosage parcelle KIN-FOU',
            'category'       => 'irrigation',
            'scheduled_date' => today()->toDateString(),
            'priority'       => 'haute',
        ])
        ->assertRedirect();

    expect(\App\Models\TaskAssignment::where('title', 'Arrosage parcelle KIN-FOU')->first()?->category)
        ->toBe('irrigation');
});

test('une catégorie inventée est refusée', function () {
    // La validation acceptait « string|max:50 » : une faute de frappe créait une
    // catégorie fantôme, invisible de tous les filtres.
    $this->actingAs($this->adminUser)
        ->post(route('tasks.store'), [
            'title'          => 'Tâche douteuse',
            'category'       => 'irrigaton',   // faute de frappe
            'scheduled_date' => today()->toDateString(),
            'priority'       => 'haute',
        ])
        ->assertSessionHasErrors('category');
});

test('le libellé d’une catégorie vient du référentiel', function () {
    $template = new TaskTemplate(['category' => 'irrigation']);
    expect($template->category_label)->toBe('💧 Irrigation');

    // Une catégorie héritée d'anciennes données reste lisible.
    expect((new TaskTemplate(['category' => 'desherbage_manuel']))->category_label)
        ->toBe('🏷️ Desherbage manuel');
});

test('une catégorie héritée reste sélectionnée à l’édition', function () {
    // Si le menu la faisait disparaître, enregistrer la fiche la remplacerait en
    // silence par la première option — une tâche changerait de nature sans un mot.
    $template = TaskTemplate::create([
        'name' => 'Ancienne tâche', 'category' => 'desherbage_manuel',
        'frequency' => 'hebdo', 'estimated_minutes' => 30,
        'priority' => 'normale', 'is_active' => true,
    ]);

    $this->actingAs($this->adminUser)->get(route('tasks.templates.edit', $template))
        ->assertOk()
        ->assertSee('value="desherbage_manuel" selected', false);
});

test('aucune vue ne recopie plus la liste des catégories', function () {
    foreach (glob(resource_path('views/tasks/*.blade.php')) as $file) {
        $source = file_get_contents($file);

        expect($source)->not->toContain("'alimentation' =>")
            ->and($source)->not->toContain('<option value="alimentation">');
    }
});
