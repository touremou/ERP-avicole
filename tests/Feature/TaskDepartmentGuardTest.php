<?php

use App\Models\Employee;
use App\Models\TaskAssignment;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Garde-fou métier du planning : une tâche revient au service concerné.
 *
 * Les services autorisés vivent avec la catégorie (TaskTemplate::CATEGORIES) ;
 * la carte était auparavant portée par TaskController et ne connaissait que les
 * six catégories d'élevage. Deux conséquences corrigées :
 *
 *   • le NETTOYAGE était réservé à l'Élevage — alors qu'on nettoie aussi la
 *     provenderie, l'abattoir et le magasin. Un nettoyage d'atelier était donc
 *     refusé à l'employé qui s'en occupe ;
 *   • les catégories de CULTURES n'étaient soumises à aucun contrôle, faute de
 *     figurer dans la carte : une tâche d'irrigation partait à n'importe qui.
 *
 * `controle`, `maintenance` et les relevés de compteurs restent volontairement
 * sans restriction : ils se pratiquent dans tous les ateliers.
 */

beforeEach(function () {
    $this->setUpRbac();
});

function farmTask(int $farmId, string $category = 'alimentation'): TaskAssignment
{
    return TaskAssignment::create([
        'farm_id'        => $farmId,
        'title'          => 'Distribuer l’aliment',
        'category'       => $category,
        'scheduled_date' => now()->toDateString(),
        'status'         => 'a_faire',
    ]);
}

test("une tâche d'ALIMENTATION ne peut PAS être assignée à un vendeur (Logistique)", function () {
    $seller = Employee::factory()->create(['farm_id' => $this->farm->id, 'department' => 'Logistique', 'status' => 'Actif']);
    $task = farmTask($this->farm->id);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('tasks.assign', $task), ['employee_id' => $seller->id])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($task->fresh()->employee_id)->toBeNull();
});

test("une tâche d'élevage peut être assignée à un employé du service Élevage", function () {
    $farmer = Employee::factory()->create(['farm_id' => $this->farm->id, 'department' => 'Elevage', 'status' => 'Actif']);
    $task = farmTask($this->farm->id);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('tasks.assign', $task), ['employee_id' => $farmer->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($task->fresh()->employee_id)->toBe($farmer->id);
});

test('création manuelle : refuse un employé du mauvais service', function () {
    // L'administration ne fait pas le ménage des ateliers.
    $clerk = Employee::factory()->create(['farm_id' => $this->farm->id, 'department' => 'Administration', 'status' => 'Actif']);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('tasks.store'), [
            'title'          => 'Nettoyer la salle',
            'category'       => 'nettoyage',
            'employee_id'    => $clerk->id,
            'scheduled_date' => now()->toDateString(),
            'priority'       => 'normale',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(TaskAssignment::where('title', 'Nettoyer la salle')->exists())->toBeFalse();
});

test('le NETTOYAGE est ouvert au magasin et aux ateliers', function () {
    // Décision de la ferme : on nettoie aussi la provenderie, l'abattoir et le
    // magasin. Le refuser bloquait l'employé qui s'en occupe réellement.
    foreach (['Logistique', 'Provenderie', 'Abattoir', 'Elevage'] as $service) {
        $employee = Employee::factory()->create([
            'farm_id' => $this->farm->id, 'department' => $service, 'status' => 'Actif',
        ]);

        $this->actingAs($this->adminUser)
            ->withSession(['current_farm_id' => $this->farm->id])
            ->post(route('tasks.store'), [
                'title'          => "Nettoyage {$service}",
                'category'       => 'nettoyage',
                'employee_id'    => $employee->id,
                'scheduled_date' => now()->toDateString(),
                'priority'       => 'normale',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        expect(TaskAssignment::where('title', "Nettoyage {$service}")->exists())->toBeTrue();
    }
});

test('une tâche de CULTURES revient au service cultures', function () {
    // Elle n'était soumise à aucun contrôle : le planning acceptait n'importe qui.
    $accountant = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'department' => 'Administration', 'status' => 'Actif',
    ]);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('tasks.store'), [
            'title'          => 'Arrosage parcelle',
            'category'       => 'irrigation',
            'employee_id'    => $accountant->id,
            'scheduled_date' => now()->toDateString(),
            'priority'       => 'normale',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('un technicien classé « Élevage » garde les tâches de cultures', function () {
    // Faute de service dédié jusqu'ici, les techniciens de cultures sont classés
    // « Élevage / Technique ». Les leur refuser du jour au lendemain bloquerait
    // le planning de Kindia et de Kérouané.
    $technician = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'department' => 'Elevage', 'status' => 'Actif',
    ]);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('tasks.store'), [
            'title'          => 'Sarclage parcelle',
            'category'       => 'sarclage',
            'employee_id'    => $technician->id,
            'scheduled_date' => now()->toDateString(),
            'priority'       => 'normale',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');
});

test('un relevé de compteur n’est réservé à personne', function () {
    // Il se fait par qui passe sur place.
    $clerk = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'department' => 'Administration', 'status' => 'Actif',
    ]);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('tasks.store'), [
            'title'          => 'Relevé compteur eau',
            'category'       => 'releve_eau',
            'employee_id'    => $clerk->id,
            'scheduled_date' => now()->toDateString(),
            'priority'       => 'normale',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');
});

test('le message d’erreur nomme le service en clair', function () {
    // « Elevage » à l'écran est une clef technique ; l'utilisateur lit un libellé.
    $clerk = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'department' => 'Administration', 'status' => 'Actif',
    ]);
    $task = farmTask($this->farm->id);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('tasks.assign', $task), ['employee_id' => $clerk->id]);

    expect(session('error'))->toContain('Élevage & Production')
        ->and(session('error'))->toContain('Administration & RH');
});
