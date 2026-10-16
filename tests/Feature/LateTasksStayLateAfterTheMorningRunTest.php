<?php

use App\Models\TaskAssignment;
use App\Services\TaskSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE TÂCHE EN RETARD LE RESTE APRÈS LE PASSAGE DU MATIN.
 *
 * `tasks:generate` (05:00) passe les tâches « à faire » des jours précédents
 * au statut « en retard ». La règle « en retard » du planning exigeait le
 * statut « à faire » : après ce passage, la liste et le compteur des tâches en
 * retard tombaient à zéro pour la journée. Le terrain comptait, lui, toute
 * tâche non terminée d'avant aujourd'hui. Une règle : TaskAssignment::overdue.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $tache = fn (string $statut, int $ilYA) => TaskAssignment::create([
        'farm_id' => $this->farm->id, 'title' => "Tâche {$statut}", 'category' => 'nettoyage',
        'scheduled_date' => today()->subDays($ilYA)->toDateString(), 'status' => $statut,
    ]);
    $tache('a_faire', 1);     // oubliée hier
    $tache('en_cours', 2);    // commencée avant-hier, jamais finie
    $tache('fait', 1);        // faite : pas en retard
    $tache('a_faire', 0);     // du jour : pas en retard
});

test('le passage du matin ne vide pas la liste des tâches en retard', function () {
    expect(TaskAssignment::overdue()->count())->toBe(2);

    app(TaskSchedulerService::class)->generateForDate(today(), $this->farm->id);

    expect(TaskAssignment::where('status', 'en_retard')->count())->toBe(1)
        ->and(TaskAssignment::overdue()->count())->toBe(2);

    $page = $this->get(route('tasks.index'))->assertOk();
    expect($page->viewData('overdueTasks'))->toHaveCount(2)
        ->and($page->viewData('stats')['overdue'])->toBe(2);
});
