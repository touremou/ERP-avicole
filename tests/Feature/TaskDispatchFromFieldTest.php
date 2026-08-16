<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\TaskAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RÉPARTIR LE TRAVAIL DEPUIS LE TERRAIN, SANS RIEN ASSOUPLIR.
 *
 * `task.create` crée une tâche PERSONNELLE, sans droit RH : un agent gère sa
 * propre liste. Affecter à AUTRUI était un autre geste, et le commentaire de la
 * synchro le disait — « reste une opération web (rh.C) ». Le chef d'équipe qui
 * répartit le travail au rassemblement du matin devait attendre d'être au
 * bureau, c'est-à-dire le soir.
 *
 * `task.dispatch` ouvre ce geste au terrain en portant les MÊMES règles que le
 * formulaire du bureau (`TaskController::storeManual`), sans en relâcher une :
 *
 *   1. droit `rh.C` ;
 *   2. l'employé doit être AFFECTABLE sur la ferme courante ;
 *   3. pas d'affectation à quelqu'un EN CONGÉ à la date prévue ;
 *   4. le SERVICE doit correspondre à la catégorie de la tâche.
 *
 * ─── POURQUOI DES « CONFLICT » ET NON DES ERREURS DE VALIDATION ───
 *
 * Le miroir local du terrain peut dater : un congé approuvé au bureau après la
 * dernière synchro n'y figure pas encore. Rejouer l'opération n'y changerait
 * rien — le refus est donc définitif, direction le bac « À corriger », avec le
 * motif en clair pour que le chef d'équipe sache quoi faire.
 *
 * ─── LA CRÉATION PERSONNELLE N'EST PAS TOUCHÉE ───
 *
 * `task.create` reste ouverte sans droit RH. Élargir cette opération-là aurait
 * exigé un droit pour un geste qui n'en demandait pas : deux gestes distincts,
 * deux opérations.
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([$this->operatorUser->id, $this->readonlyUser->id] as $uid) {
        DB::table('farm_user')->insert([
            'farm_id' => $this->farm->id, 'user_id' => $uid,
            'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

/** Un agent du service demandé, affectable sur la ferme. */
function agent(int $farmId, string $prenom, string $service = 'Elevage'): Employee
{
    return Employee::create([
        'farm_id' => $farmId,
        'first_name' => $prenom, 'last_name' => 'Camara',
        'gender' => 'M', 'phone' => '6' . random_int(10_000_000, 99_999_999),
        'department' => $service, 'job_title' => 'Technicien', 'contract_type' => 'cdi',
        'hire_date' => now()->subYear()->toDateString(),
        'status' => 'Actif',
    ]);
}

/** Envoie une affectation depuis le terrain. */
function affecter(array $payload): array
{
    $reponse = test()->postJson('/api/v1/sync/push', [
        'operations' => [[
            'op_uuid' => (string) Str::uuid(),
            'type'    => 'task.dispatch',
            'payload' => array_merge([
                'uuid'           => (string) Str::uuid(),
                'title'          => 'Nettoyage du poulailler B2',
                'category'       => 'nettoyage',
                'scheduled_date' => now()->toDateString(),
            ], $payload),
        ]],
    ])->assertOk();

    return $reponse->json('results.0');
}

test('un chef d’équipe affecte une tâche à un collègue depuis le terrain', function () {
    Sanctum::actingAs($this->managerUser);   // rh.C
    $collegue = agent($this->farm->id, 'Mamadou', 'Elevage');

    $r = affecter(['employee_id' => $collegue->id]);

    expect($r['status'])->toBe('success')
        ->and(TaskAssignment::first()->employee_id)->toBe($collegue->id);
});

test('sans droit rh.C, l’affectation est refusée', function () {
    /*
     * La condition posée : le geste s'ouvre au terrain, pas à tout le monde.
     * Le profil LECTURE SEULE n'a que « L » — il consulte, il n'affecte pas.
     */
    Sanctum::actingAs($this->readonlyUser);
    $collegue = agent($this->farm->id, 'Mamadou', 'Elevage');

    expect(affecter(['employee_id' => $collegue->id])['status'])->toBe('permission_denied')
        ->and(TaskAssignment::count())->toBe(0);
});

test('affecter à quelqu’un EN CONGÉ est refusé, avec le motif', function () {
    // La règle du bureau : « X est en congé le … Choisissez un collègue
    // disponible. » Elle doit traverser telle quelle.
    Sanctum::actingAs($this->managerUser);
    $absent = agent($this->farm->id, 'Mamadou', 'Elevage');

    EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $absent->id,
        'type' => 'conge_annuel', 'status' => 'approuve', 'days_count' => 5,
        'start_date' => now()->subDays(2)->toDateString(),
        'end_date' => now()->addDays(2)->toDateString(),
    ]);

    $r = affecter(['employee_id' => $absent->id]);

    expect($r['status'])->toBe('conflict')
        ->and($r['message'])->toContain('congé')
        ->and(TaskAssignment::count())->toBe(0);
});

test('affecter hors du SERVICE concerné est refusé', function () {
    // Une tâche « nettoyage » revient à Élevage / Logistique / Provenderie /
    // Abattoir — pas au Commerce.
    Sanctum::actingAs($this->managerUser);
    $vendeur = agent($this->farm->id, 'Fatoumata', 'Commerce');

    $r = affecter(['employee_id' => $vendeur->id]);

    expect($r['status'])->toBe('conflict')
        ->and(TaskAssignment::count())->toBe(0);
});

test('sans titulaire, la tâche part au POOL et non dans le vide', function () {
    /*
     * `is_pool` est DÉRIVÉ, comme au bureau. Le coder en dur avait produit,
     * dans le générateur d'itinéraire, des tâches qu'aucun téléphone ne voyait :
     * ni « les miennes », ni « le pool ».
     */
    Sanctum::actingAs($this->managerUser);

    expect(affecter([])['status'])->toBe('success');

    $tache = TaskAssignment::first();

    expect($tache->employee_id)->toBeNull()
        ->and($tache->is_pool)->toBeTrue();
});

test('une catégorie inventée est refusée', function () {
    // Même liste contrainte qu'au bureau : une faute de frappe rendrait la
    // tâche invisible de tous les filtres.
    Sanctum::actingAs($this->managerUser);

    expect(affecter(['category' => 'bricolage'])['status'])->toBe('validation_failed');
});

test('le rejeu de la même opération ne duplique pas', function () {
    // Contrat de la file d'attente hors-ligne : le réseau coupe, on rejoue.
    Sanctum::actingAs($this->managerUser);
    $collegue = agent($this->farm->id, 'Mamadou', 'Elevage');

    $uuid = (string) Str::uuid();
    $payload = ['uuid' => $uuid, 'employee_id' => $collegue->id];

    expect(affecter($payload)['status'])->toBe('success')
        ->and(affecter($payload)['status'])->toBe('already_synced')
        ->and(TaskAssignment::count())->toBe(1);
});

test('la tâche personnelle reste ouverte SANS droit RH', function () {
    /*
     * La borne qui protège l'existant : `task.create` ne demandait aucun droit
     * RH, et ne doit pas s'en voir imposer un. Deux gestes, deux opérations.
     */
    $operateur = agent($this->farm->id, 'Aissatou', 'Elevage');
    $operateur->update(['user_id' => $this->operatorUser->id]);

    Sanctum::actingAs($this->operatorUser);

    $r = $this->postJson('/api/v1/sync/push', [
        'operations' => [[
            'op_uuid' => (string) Str::uuid(),
            'type'    => 'task.create',
            'payload' => [
                'uuid' => (string) Str::uuid(),
                'title' => 'Repasser voir la pondeuse boiteuse',
                'category' => 'nettoyage',
                'scheduled_date' => now()->toDateString(),
            ],
        ]],
    ])->assertOk();

    expect($r->json('results.0.status'))->toBe('success')
        ->and(TaskAssignment::first()->employee_id)->toBe($operateur->id);
});
