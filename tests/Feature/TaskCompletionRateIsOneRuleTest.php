<?php

use App\Models\TaskAssignment;
use App\Services\TaskSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * TAUX DE RÉALISATION ET RETARDS : LES RÈGLES DU MODÈLE, AU CALENDRIER AUSSI.
 *
 * Le taux comptait les tâches ANNULÉES parmi les tâches à faire : une journée
 * où tout ce qui restait à faire était fait affichait 50 %. Et le calendrier
 * ne comptait « en retard » que le statut posé par le passage du matin — pas
 * une tâche commencée la veille et jamais finie.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $tache = fn (string $statut, $date) => TaskAssignment::create([
        'farm_id' => $this->farm->id, 'title' => "Tâche {$statut}", 'category' => 'nettoyage',
        'scheduled_date' => $date->toDateString(), 'status' => $statut,
    ]);
    $this->hier = today()->subDay();
    $tache('fait', $this->hier);
    $tache('annule', $this->hier);
    $tache('en_cours', $this->hier);   // commencée, jamais finie : en retard

    $tache('fait', today());
    $tache('annule', today());
});

test('une tâche annulée ne fait pas baisser le taux de réalisation', function () {
    expect(TaskAssignment::tauxDeRealisation(TaskAssignment::whereDate('scheduled_date', today())->get()))->toBe(100)
        ->and(app(TaskSchedulerService::class)->getDashboardStats(today(), $this->farm->id)['rate'])->toBe(100);
});

test('le calendrier compte en retard la tâche commencée la veille, et le taux sans les annulées', function () {
    $jour = $this->get(route('tasks.index', ['view' => 'month', 'date' => $this->hier->toDateString()]))
        ->assertOk()->viewData('calendarData')[$this->hier->format('Y-m-d')];

    expect($jour['late'])->toBe(1)
        ->and($jour['rate'])->toBe(50);   // 1 faite sur 2 à faire (l'annulée exclue)
});
