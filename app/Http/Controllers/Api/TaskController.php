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
            return response()->json(['tasks' => [], 'server_time' => now()->toIso8601String()]);
        }

        $tasks = TaskAssignment::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['a_faire', 'en_cours', 'en_retard'])
            ->where('scheduled_date', '<=', now()->addDays(7)->toDateString())
            ->orderBy('scheduled_date')
            ->orderByRaw('scheduled_time IS NULL, scheduled_time')
            ->get([
                'id', 'title', 'category', 'priority', 'status',
                'scheduled_date', 'scheduled_time', 'batch_id', 'building_id', 'plot_id',
            ]);

        return response()->json([
            'tasks'       => $tasks,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
