<?php

namespace App\Http\Controllers;

use App\Services\ConsolidatedSitesService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Vue consolidée MULTI-SITES — la page du promoteur.
 *
 * Le FarmScope isole chaque ferme (correct pour un technicien) mais obligeait à
 * basculer de site en site pour comparer. Cette page affiche les mêmes lignes
 * pour chaque site, côte à côte.
 *
 * L'autorisation est portée par le service (propriétaire d'un site OU admin) et
 * le périmètre est strictement le `farm_user` de l'utilisateur : ni middleware
 * `can:L` — qui rattacherait la page à un module unique — ni withoutFarm() nu.
 */
class ConsolidatedSitesController extends Controller
{
    public function index(Request $request, ConsolidatedSitesService $service)
    {
        $user = Auth::user();

        if (! $service->canConsolidate($user)) {
            return redirect()->route('dashboard')
                ->with('error', 'La vue consolidée est réservée aux propriétaires de sites.');
        }

        $week = $this->resolveWeek($request->input('week'));
        $sites = $service->forUser($user, $week);

        return view('consolide.index', [
            'week'  => $week,
            'sites' => $sites,
            // Somme des sites : le total du groupe, que la bascule ferme à ferme
            // ne permettait pas d'obtenir.
            'totals' => $this->totals($sites),
        ]);
    }

    /**
     * Agrégat de groupe. On somme ce qui est SOMMABLE (effectifs, montants,
     * comptes) et on recalcule les taux depuis les numérateurs/dénominateurs —
     * moyenner des pourcentages de sites de tailles différentes donnerait un
     * chiffre faux.
     *
     * @param  array<int, array<string, mixed>>  $sites
     * @return array<string, mixed>
     */
    private function totals(array $sites): array
    {
        $sum = fn (string $group, string $key) => array_sum(array_map(fn ($s) => $s[$group][$key] ?? 0, $sites));

        $tasksTotal = $sum('tasks', 'total');
        $tasksDone  = $sum('tasks', 'done');

        return [
            'sites'            => count($sites),
            'active_batches'   => $sum('elevage', 'active_batches'),
            'live_subjects'    => $sum('elevage', 'live_subjects'),
            'active_cycles'    => $sum('cultures', 'active_cycles'),
            'area_ha'          => round(array_sum(array_map(fn ($s) => $s['cultures']['area_ha'] ?? 0, $sites)), 2),
            'late_steps'       => $sum('cultures', 'late_steps'),
            'open_incidents'   => $sum('sanitaire', 'open_incidents'),
            'low_items'        => $sum('stock', 'low_items'),
            'week_revenue'     => round(array_sum(array_map(fn ($s) => $s['commerce']['week_revenue'] ?? 0, $sites)), 2),
            'open_receivable'  => round(array_sum(array_map(fn ($s) => $s['commerce']['open_receivable'] ?? 0, $sites)), 2),
            'tasks_total'      => $tasksTotal,
            'tasks_done'       => $tasksDone,
            'tasks_late'       => $sum('tasks', 'late'),
            'completion'       => $tasksTotal > 0 ? round($tasksDone / $tasksTotal * 100, 1) : null,
        ];
    }

    /** Semaine demandée (« 2026-W31 » ou date libre), sinon la semaine courante. */
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
