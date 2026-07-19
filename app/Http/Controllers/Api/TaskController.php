<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaskAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tâches assignées (terrain) — miroir mobile de la liste « Mes tâches ».
 *
 * Renvoie les tâches ACTIONNABLES de l'employé rattaché à l'utilisateur
 * (a_faire / en_cours / en_retard), dans une fenêtre proche, pour un
 * affichage « ce qui m'est assigné aujourd'hui ». Bornée à la ferme
 * courante par FarmScope (BelongsToFarm). Remplacement complet côté client
 * (comme les notifications) : pas de delta/tombstone à gérer.
 */
class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employeeId = $request->user()->employee?->id;

        // Sans employé rattaché (admin/superviseur), pas de liste personnelle.
        if (! $employeeId) {
            return response()->json([
                'tasks'       => [],
                'summary'     => ['today' => 0, 'overdue' => 0, 'upcoming' => 0, 'high_priority' => 0, 'done_today' => 0],
                'server_time' => now()->toIso8601String(),
            ]);
        }

        $today    = now()->toDateString();
        $horizon  = now()->addDays(7)->toDateString();
        $doable   = ['a_faire', 'en_cours', 'en_retard'];

        $tasks = TaskAssignment::query()
            ->with('claimant:id,name')
            // Mes tâches assignées + les tâches du POOL (libre-service) encore à
            // prendre : le premier arrivé se les attribue. Bornées à la ferme
            // courante par le FarmScope.
            ->where(function ($q) use ($employeeId) {
                $q->where('employee_id', $employeeId)
                  ->orWhere(fn ($p) => $p->where('is_pool', true)->where('status', 'a_faire'));
            })
            ->whereIn('status', $doable)
            ->where('scheduled_date', '<=', $horizon)
            ->orderBy('scheduled_date')
            ->orderByRaw('scheduled_time IS NULL, scheduled_time')
            ->get([
                'id', 'title', 'category', 'priority', 'status',
                'scheduled_date', 'scheduled_time', 'batch_id', 'building_id', 'plot_id',
                'proof_type', 'proof_label', 'proof_unit',
                'started_at', 'claimed_by', 'is_pool',
            ]);

        // Verrou de tâche (anti-doublon) : on expose l'état de prise. `locked`
        // = prise ACTIVE (non expirée) par un AUTRE utilisateur → l'UI grise la
        // tâche. `claimed_by_me` = ma propre prise en cours (bouton « Terminer »).
        $userId = $request->user()->id;
        $tasks->each(function (TaskAssignment $task) use ($userId) {
            $task->setAttribute('locked', $task->isClaimedByOther($userId));
            $task->setAttribute('claimed_by_me',
                $task->status === 'en_cours' && $task->claimed_by === $userId && ! $task->isClaimStale());
            $task->setAttribute('claimant_name', $task->claimant?->name);
            $task->unsetRelation('claimant');
        });

        // Récap « ma journée » : l'en-cours vient de la liste (mêmes bornes), le
        // « fait aujourd'hui » se calcule à part (les tâches closes en sont exclues).
        $doneToday = TaskAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'fait')
            ->whereDate('completed_at', $today)
            ->count();

        return response()->json([
            'tasks'   => $tasks,
            'summary' => [
                'today'         => $tasks->filter(fn ($t) => $t->scheduled_date->toDateString() === $today)->count(),
                'overdue'       => $tasks->filter(fn ($t) => $t->scheduled_date->toDateString() < $today)->count(),
                'upcoming'      => $tasks->filter(fn ($t) => $t->scheduled_date->toDateString() > $today)->count(),
                'high_priority' => $tasks->whereIn('priority', ['haute', 'critique'])->count(),
                'done_today'    => $doneToday,
            ],
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
