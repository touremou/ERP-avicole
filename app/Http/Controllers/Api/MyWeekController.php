<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TechnicianWeekService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MA SEMAINE (terrain) — l'auto-suivi du lundi matin, sur le téléphone.
 *
 * Le technicien consulte SES indicateurs sans droit RH : c'est sa propre
 * performance, et l'auto-suivi est le premier niveau du dispositif. Il ne voit
 * jamais ceux de ses collègues (aucun comparatif ici, contrairement au web).
 *
 * Même calcul que la page web (TechnicianWeekService) : le chiffre du téléphone
 * et celui du bureau doivent être identiques, sinon le débriefing se transforme
 * en discussion sur l'outil.
 */
class MyWeekController extends Controller
{
    public function index(Request $request, TechnicianWeekService $service): JsonResponse
    {
        $employee = $request->user()->employee;

        // Sans fiche RH rattachée (admin, superviseur), pas de semaine personnelle.
        if (! $employee) {
            return response()->json([
                'has_sheet'   => false,
                'server_time' => now()->toIso8601String(),
            ]);
        }

        $week = $this->resolveWeek($request->input('week'));
        $sheet = $service->forEmployee($employee, $week);

        return response()->json([
            'has_sheet'  => true,
            'employee'   => [
                'id'        => $employee->id,
                'name'      => trim($employee->first_name . ' ' . $employee->last_name),
                'job_title' => $employee->job_title,
            ],
            'week' => [
                'iso'  => $sheet['from']->isoWeek(),
                'year' => $sheet['from']->isoWeekYear(),
                'from' => $sheet['from']->toDateString(),
                'to'   => $sheet['to']->toDateString(),
            ],
            'indicators' => $sheet['indicators'],
            'tasks'      => $sheet['tasks'],
            'batches'    => $sheet['batches'],
            'cycles'     => $sheet['cycles'],
            'incidents'  => $sheet['incidents'],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** Semaine demandée (« 2026-W31 » ou date), sinon la semaine courante. */
    private function resolveWeek(?string $value): Carbon
    {
        if (! $value) {
            return now()->startOfWeek();
        }

        try {
            if (preg_match('/^(\d{4})-W(\d{1,2})$/', $value, $m)) {
                return Carbon::now()->setISODate((int) $m[1], (int) $m[2])->startOfWeek();
            }

            return Carbon::parse($value)->startOfWeek();
        } catch (\Throwable) {
            return now()->startOfWeek();
        }
    }
}
