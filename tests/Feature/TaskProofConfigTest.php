<?php

use App\Models\Building;
use App\Models\Employee;
use App\Models\TaskAssignment;
use App\Models\TaskTemplate;
use App\Services\TaskSchedulerService;
use Carbon\Carbon;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Configuration de la preuve d'exécution par le superviseur (écrans web des
 * templates de tâches) : création/édition de l'exigence, et propagation à la
 * génération des tâches.
 */

beforeEach(function () {
    $this->setUpRbac();
    TaskTemplate::withoutGlobalScopes()->update(['is_active' => false]);
});

test('le superviseur crée un template avec exigence de preuve VALEUR', function () {
    $this->actingAs($this->managerUser)
        ->post(route('tasks.templates.store'), [
            'name' => 'Relevé mortalité B1', 'category' => 'controle', 'frequency' => 'quotidien',
            'duration_minutes' => 20, 'priority' => 'critique',
            'proof_type' => 'valeur', 'proof_label' => 'Nombre de morts', 'proof_unit' => 'sujets',
        ])
        ->assertSessionHasNoErrors()->assertRedirect();

    $tpl = TaskTemplate::where('name', 'Relevé mortalité B1')->first();
    expect($tpl)->not->toBeNull()
        ->and($tpl->proof_type)->toBe('valeur')
        ->and($tpl->proof_label)->toBe('Nombre de morts')
        ->and($tpl->proof_unit)->toBe('sujets');
});

test('éditer un template met à jour l\'exigence de preuve (et l\'unité est vidée si photo)', function () {
    $tpl = TaskTemplate::create([
        'name' => 'Alimentation B2', 'category' => 'alimentation', 'frequency' => 'quotidien',
        'duration_minutes' => 30, 'priority' => 'haute', 'target_type' => 'building',
        'proof_type' => 'valeur', 'proof_label' => 'X', 'proof_unit' => 'kg', 'is_active' => true,
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('tasks.templates.update', $tpl->id), [
            'name' => 'Alimentation B2', 'category' => 'alimentation', 'frequency' => 'quotidien',
            'duration_minutes' => 30, 'priority' => 'haute',
            'proof_type' => 'photo', 'proof_label' => 'Photo du sac vidé', 'proof_unit' => 'kg',
        ])
        ->assertRedirect(route('tasks.templates'));

    $tpl->refresh();
    expect($tpl->proof_type)->toBe('photo')
        ->and($tpl->proof_label)->toBe('Photo du sac vidé')
        ->and($tpl->proof_unit)->toBeNull(); // unité pertinente seulement pour « valeur »
});

test('la génération propage l\'exigence de preuve du template à la tâche', function () {
    Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    // Cible « ferme » (per_building=false) → une tâche générée sans dépendre
    // d'un bâtiment/lot actif ; on mesure la propagation de la preuve.
    TaskTemplate::create([
        'name' => 'Contrôle ferme avec photo', 'category' => 'controle', 'frequency' => 'quotidien',
        'duration_minutes' => 15, 'priority' => 'normale', 'target_type' => 'farm',
        'per_building' => false, 'proof_type' => 'photo', 'proof_label' => 'Photo état', 'is_active' => true,
    ]);

    app(TaskSchedulerService::class)->generateForDate(Carbon::parse('2026-07-20'), $this->farm->id);

    $task = TaskAssignment::where('title', 'like', 'Contrôle ferme avec photo%')->first();
    expect($task)->not->toBeNull()
        ->and($task->proof_type)->toBe('photo')
        ->and($task->proof_label)->toBe('Photo état');
});
