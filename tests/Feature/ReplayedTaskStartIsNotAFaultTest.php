<?php

use App\Models\Employee;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * UNE OPÉRATION QUI A RÉUSSI PARTAIT AU BAC « À CORRIGER ».
 *
 * La file du terrain pousse par LOTS de 50 (`mobile/src/offline/sync.ts`), et ne
 * retire une opération de la file qu'après avoir LU son résultat. Si la réponse
 * se perd — coupure réseau après que le serveur a écrit, ce qui est la norme sur
 * les appareils du terrain — aucun résultat n'est traité : le lot ENTIER reste
 * en attente et repart tel quel au tour suivant.
 *
 * Un ouvrier qui prend puis termine une tâche hors réseau pousse donc le couple
 * [task.start, task.complete] — et le rejoue en entier.
 *
 * Mesuré, sur ce rejeu :
 *
 *   • `task.complete` répond `already_synced` → l'op quitte la file, correct ;
 *   • `task.start` répond `conflict` — « Tâche déjà terminée. »
 *
 * Or `conflict` est un refus DÉFINITIF côté client : l'op sort de la file vers
 * le bac « À corriger », avec un motif que rien ne permet d'arbitrer. L'ouvrier
 * voit un manquement pour une prise de tâche qui a RÉUSSI, sur une tâche qu'il a
 * lui-même terminée. Il ne peut ni la rejouer, ni la corriger : il ne peut que
 * l'abandonner, et l'entrée reste comme une trace d'échec dans son bac.
 *
 * ─── TROIS VOISINS, UNE MÊME SITUATION, UNE RÉPONSE DIVERGENTE ───
 *
 * Les deux autres gestes du même verrou, écrits dans le même fichier à quelques
 * lignes de distance, traitent déjà « la tâche est close » comme un rejeu :
 *
 *   • `taskComplete`  → `if ($task->status === 'fait') return already_synced;`
 *   • `taskRelease`   → `if ($task->status === 'fait') return already_synced;`
 *                        (« rien à libérer, déjà close »)
 *   • `taskStart`     → `conflict`
 *
 * ─── CE QU'ON NE FAIT PAS ───
 *
 * On ne rend pas le refus inconditionnellement muet. Un AUTRE ouvrier qui tente
 * de prendre une tâche qu'un collègue a déjà terminée doit continuer à l'ap-
 * prendre : c'est le seul canal qui l'empêche de refaire le travail. La distinc-
 * tion est celle que porte déjà la ligne : `completed_by`. Si c'est MOI qui ai
 * clos la tâche, ma prise fait partie de la séquence déjà appliquée — c'est un
 * rejeu, pas un conflit.
 */

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-REJEU'], ['name' => 'Ferme Rejeu', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);

    $module = Module::where('slug', 'rh')->value('id');
    $role = Role::firstOrCreate(
        ['name' => 'ouvrier_rejeu'],
        ['label' => 'Ouvrier', 'display_name' => 'Ouvrier', 'permissions' => []],
    );
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $module],
        ['can_read' => true, 'can_create' => false, 'can_modify' => false,
         'can_delete' => false, 'created_at' => now(), 'updated_at' => now()],
    );

    // Deux ouvriers de la même ferme : le titulaire de la tâche, et un collègue
    // qui pourrait légitimement tenter de la prendre en libre-service.
    foreach (['agent' => 'agentEmp', 'collegue' => 'collegueEmp'] as $compte => $fiche) {
        $this->$compte = User::factory()->create(['role_id' => $role->id]);
        DB::table('farm_user')->insert([
            'farm_id' => $this->farm->id, 'user_id' => $this->$compte->id,
            'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->$fiche = Employee::factory()->create([
            'farm_id' => $this->farm->id, 'user_id' => $this->$compte->id,
        ]);
    }
});

/** Une tâche de libre-service, que n'importe quel ouvrier de la ferme peut prendre. */
function tacheDeLibreServiceARejouer(int $farmId): TaskAssignment
{
    return TaskAssignment::create([
        'farm_id'        => $farmId,
        'employee_id'    => null,
        'is_pool'        => true,
        'title'          => 'Nettoyage cour',
        'category'       => 'nettoyage',
        'scheduled_date' => now()->toDateString(),
        'status'         => 'a_faire',
    ]);
}

/**
 * Pousse un LOT d'opérations comme le fait la file du terrain, et rend les
 * statuts dans l'ordre. Chaque envoi porte des `op_uuid` neufs : c'est bien le
 * même GESTE rejoué, pas la même ligne de file relue.
 */
function pousserLeLotTerrain(object $test, User $agent, array $gestes): array
{
    Sanctum::actingAs($agent);

    $operations = array_map(fn (array $g) => [
        'op_uuid' => Str::uuid()->toString(),
        'type'    => $g[0],
        'payload' => ['uuid' => Str::uuid()->toString(), 'task_id' => $g[1]],
    ], $gestes);

    $resultats = $test->postJson('/api/v1/sync/push', ['operations' => $operations])
        ->assertOk()
        ->json('results');

    return array_column($resultats, 'status');
}

test('le lot [prise, clôture] rejoué en entier ne produit aucun manquement', function () {
    /*
     * LE défaut, mesuré de bout en bout par la vraie porte du terrain.
     */
    $tache = tacheDeLibreServiceARejouer($this->farm->id);

    $premier = pousserLeLotTerrain($this, $this->agent, [
        ['task.start', $tache->id],
        ['task.complete', $tache->id],
    ]);

    expect($premier)->toBe(['success', 'success'])
        ->and($tache->fresh()->status)->toBe('fait');

    // La réponse s'est perdue : la file repousse le lot ENTIER, inchangé.
    $rejeu = pousserLeLotTerrain($this, $this->agent, [
        ['task.start', $tache->id],
        ['task.complete', $tache->id],
    ]);

    // Aucun des deux ne doit partir au bac « À corriger ».
    expect($rejeu)->toBe(['already_synced', 'already_synced']);
});

test('le rejeu ne ROUVRE pas la tâche close', function () {
    /*
     * LA borne de la correction : rendre la prise idempotente ne doit pas la
     * rendre agissante. Une tâche terminée le reste, avec son auteur et son
     * horodatage intacts.
     */
    $tache = tacheDeLibreServiceARejouer($this->farm->id);

    pousserLeLotTerrain($this, $this->agent, [
        ['task.start', $tache->id],
        ['task.complete', $tache->id],
    ]);

    $close = $tache->fresh();

    pousserLeLotTerrain($this, $this->agent, [['task.start', $tache->id]]);

    $apres = $tache->fresh();

    expect($apres->status)->toBe('fait')
        ->and($apres->completed_by)->toBe($this->agent->id)
        ->and($apres->completed_at?->toDateTimeString())->toBe($close->completed_at?->toDateTimeString())
        ->and($apres->claimed_by)->toBe($close->claimed_by);
});

test('un COLLÈGUE apprend toujours que la tâche est terminée — non-régression', function () {
    /*
     * Ce qu'on ne supprime pas : le seul canal qui empêche un second ouvrier de
     * refaire un travail déjà fait. Il n'a pas clos cette tâche, donc ce n'est
     * pas un rejeu de sa séquence — c'est un conflit, et il doit le voir.
     */
    $tache = tacheDeLibreServiceARejouer($this->farm->id);

    pousserLeLotTerrain($this, $this->agent, [
        ['task.start', $tache->id],
        ['task.complete', $tache->id],
    ]);

    expect(pousserLeLotTerrain($this, $this->collegue, [['task.start', $tache->id]]))
        ->toBe(['conflict']);
});

test('la prise rejouée sur une tâche EN COURS reste idempotente — non-régression', function () {
    // L'autre rejeu, déjà correct : la réponse s'est perdue avant la clôture.
    $tache = tacheDeLibreServiceARejouer($this->farm->id);

    expect(pousserLeLotTerrain($this, $this->agent, [['task.start', $tache->id]]))->toBe(['success']);
    expect(pousserLeLotTerrain($this, $this->agent, [['task.start', $tache->id]]))->toBe(['already_synced']);

    expect($tache->fresh()->status)->toBe('en_cours');
});

test('la libération d’une tâche close reste un rejeu — non-régression', function () {
    /*
     * Le troisième voisin, celui qui disait déjà juste : « rien à libérer, déjà
     * close ». C'est de lui que la prise s'aligne.
     */
    $tache = tacheDeLibreServiceARejouer($this->farm->id);

    pousserLeLotTerrain($this, $this->agent, [
        ['task.start', $tache->id],
        ['task.complete', $tache->id],
    ]);

    expect(pousserLeLotTerrain($this, $this->agent, [['task.release', $tache->id]]))
        ->toBe(['already_synced'])
        ->and($tache->fresh()->status)->toBe('fait');
});
