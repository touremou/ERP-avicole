<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\CropCycle;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\HealthIncident;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\TaskAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ConsolidatedSitesService — les sites du promoteur, côte à côte.
 *
 * Problème résolu : le FarmScope isole chaque ferme, ce qui est CORRECT pour un
 * technicien, mais oblige le promoteur à basculer de site en site pour comparer.
 * Ce service produit les MÊMES lignes d'indicateurs pour chaque site, en un seul
 * passage.
 *
 * ── CLOISONNEMENT (le point critique) ──
 * On n'utilise JAMAIS un withoutFarm() nu. Le périmètre est strictement la liste
 * des fermes de la table `farm_user` de l'utilisateur : le jour où l'ERP héberge
 * un tiers, cette page ne peut pas fuir ses données. Un withoutFarm() global
 * serait passé silencieusement de « mes deux sites » à « toutes les fermes
 * hébergées ».
 *
 * ── COMMENT ON CALCULE PAR SITE ──
 * Les modèles agrégés (Batch, DailyCheck, Sale, Stock…) sont bornés par le
 * FarmScope, lui-même piloté par session('current_farm_id'). Plutôt que de
 * réécrire chaque requête avec un forFarm() explicite — ce qui dupliquerait la
 * règle de cloisonnement et raterait les accesseurs qui traversent des relations
 * (Batch::fcr_corrected → dailyChecks) — on POINTE temporairement la session sur
 * chaque ferme, sous try/finally.
 *
 * Le finally n'est pas cosmétique : sans lui, une exception au milieu de la
 * boucle laisserait l'utilisateur sur la ferme d'un autre site à la page
 * suivante. Service en LECTURE SEULE, ce qui rend cette bascule sans effet de
 * bord sur les données.
 */
class ConsolidatedSitesService
{
    /**
     * Fermes que l'utilisateur peut consolider : celles de son `farm_user`.
     *
     * @return \Illuminate\Support\Collection<int, Farm>
     */
    public function accessibleFarms(User $user)
    {
        $ids = DB::table('farm_user')->where('user_id', $user->id)->pluck('farm_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return Farm::whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Fermes à consolider POUR UNE SEMAINE DONNÉE.
     *
     * Le filtre « actif » portait sur l'état d'AUJOURD'HUI, pour une photo d'une
     * semaine PASSÉE. Désactiver un site en fin de mois effaçait donc sa
     * production des semaines déjà écoulées : le comparatif accusait une chute
     * qui n'avait pas eu lieu, provoquée par un geste administratif et non par
     * l'exploitation. Un promoteur qui compare ses mois y lit une contre-performance
     * imaginaire.
     *
     * Règle : un site figure dans la semaine s'il est ACTIF AUJOURD'HUI — il a sa
     * place même à zéro, c'est un site qu'on pilote — OU s'il a PRODUIT quelque
     * chose cette semaine-là, même désactivé depuis. Le passé ne se réécrit pas.
     *
     * @return \Illuminate\Support\Collection<int, Farm>
     */
    public function farmsForWeek(User $user, Carbon $from, Carbon $to)
    {
        $ids = DB::table('farm_user')->where('user_id', $user->id)->pluck('farm_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        // Sites désactivés qui ont tout de même travaillé pendant la semaine.
        // On interroge les écritures datées que la photo elle-même restitue :
        // si le tableau afficherait autre chose que des zéros, le site y a sa place.
        $withActivity = collect();

        foreach ([
            ['sales', 'sale_date'],
            ['task_assignments', 'scheduled_date'],
            ['daily_checks', 'check_date'],
        ] as [$table, $column]) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }

            $withActivity = $withActivity->merge(
                DB::table($table)
                    ->whereIn('farm_id', $ids)
                    ->whereDate($column, '>=', $from->toDateString())
                    ->whereDate($column, '<=', $to->toDateString())
                    ->distinct()
                    ->pluck('farm_id')
            );
        }

        return Farm::whereIn('id', $ids)
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhereIn('id', $withActivity->unique()->all()))
            ->orderBy('name')
            ->get();
    }

    /**
     * L'utilisateur a-t-il le droit de voir la consolidation ?
     *
     * Deux profils, et pas plus : l'administrateur, et le PROPRIÉTAIRE d'au
     * moins un site (colonne is_owner du farm_user) — qui est précisément le cas
     * du promoteur, lequel n'a pas forcément le rôle « admin ». Un technicien de
     * Kindia ne voit jamais les chiffres de Kérouané.
     */
    public function canConsolidate(User $user): bool
    {
        if (\Illuminate\Support\Facades\Gate::forUser($user)->allows('admin.L')) {
            return true;
        }

        return DB::table('farm_user')
            ->where('user_id', $user->id)
            ->where('is_owner', true)
            ->exists();
    }

    /**
     * Tableau consolidé : une entrée par site, avec les mêmes lignes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUser(User $user, ?Carbon $weekStart = null): array
    {
        $week = ($weekStart ?? now())->copy()->startOfWeek();
        $farms = $this->farmsForWeek($user, $week, $week->copy()->endOfWeek());

        // On restaure la ferme d'origine quoi qu'il arrive (cf. docblock).
        $original = session('current_farm_id');

        try {
            return $farms->map(fn (Farm $farm) => $this->snapshotFor($farm, $week))->values()->all();
        } finally {
            session(['current_farm_id' => $original]);
        }
    }

    /**
     * Photo d'un site à la semaine donnée.
     *
     * @return array<string, mixed>
     */
    private function snapshotFor(Farm $farm, Carbon $week): array
    {
        session(['current_farm_id' => $farm->id]);

        $to = $week->copy()->endOfWeek();

        $batches = Batch::query()->active()->live()->get();
        $tasks = $this->taskStats($week, $to);

        return [
            'farm'      => $farm,
            // Un site désactivé qui figure encore dans une semaine passée doit se
            // SIGNALER : sans cela, on lit ses chiffres comme ceux d'un site en
            // service et l'on s'étonne qu'il ne produise plus rien ensuite.
            'inactive'  => ! $farm->is_active,
            'elevage'   => $this->elevage($batches),
            'cultures'  => $this->cultures(),
            'tasks'     => $tasks,
            'sanitaire' => [
                'open_incidents' => HealthIncident::open()->count(),
            ],
            'stock'     => $this->stock(),
            'commerce'  => $this->commerce($week, $to),
            'team'      => $this->team($week),
        ];
    }

    /** @param \Illuminate\Support\Collection<int, Batch> $batches */
    private function elevage($batches): array
    {
        $rates = $batches->map(fn (Batch $b) => $b->mortality_rate)->filter(fn ($r) => $r !== null);
        $fcrs = $batches->map(fn (Batch $b) => $b->fcr_corrected)->filter(fn ($f) => $f !== null && $f > 0);

        return [
            'active_batches'  => $batches->count(),
            'live_subjects'   => (int) $batches->sum('current_quantity'),
            // La PIRE mortalité, pas la moyenne : c'est elle qui appelle un appel.
            'worst_mortality' => $rates->isEmpty() ? null : round($rates->max(), 2),
            'avg_fcr'         => $fcrs->isEmpty() ? null : round($fcrs->avg(), 2),
        ];
    }

    private function cultures(): array
    {
        $cycles = CropCycle::query()->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)->get();

        // Étapes d'itinéraire en retard, tous cycles confondus : le signal le plus
        // parlant sur la tenue du plan de culture d'un site.
        $lateSteps = TaskAssignment::query()
            ->whereNotNull('crop_protocol_item_id')
            ->where('status', 'en_retard')
            ->count();

        return [
            'active_cycles' => $cycles->count(),
            'area_ha'       => round((float) $cycles->sum('area_used_ha'), 2),
            'late_steps'    => $lateSteps,
        ];
    }

    /**
     * Tâches de la semaine, tous techniciens du site confondus.
     *
     * Même définition que la fiche hebdomadaire : dénominateur = tâches
     * PLANIFIÉES dans la semaine. Bornes en whereDate (la colonne est castée en
     * date et stockée avec l'heure sous sqlite : un whereBetween exclurait le
     * dernier jour).
     *
     * @return array<string, int|float|null>
     */
    private function taskStats(Carbon $from, Carbon $to): array
    {
        $rows = TaskAssignment::query()
            ->whereDate('scheduled_date', '>=', $from->toDateString())
            ->whereDate('scheduled_date', '<=', $to->toDateString())
            ->get(['status']);

        $done = $rows->where('status', 'fait')->count();

        return [
            'total'      => $rows->count(),
            'done'       => $done,
            'late'       => $rows->where('status', 'en_retard')->count(),
            // null = aucune tâche planifiée : « non mesurable », pas 0 %.
            'completion' => $rows->count() > 0 ? round($done / $rows->count() * 100, 1) : null,
        ];
    }

    private function stock(): array
    {
        // is_low est un accesseur (comparaison quantité/seuil) : on le filtre en
        // mémoire, le nombre d'articles par site restant modeste.
        $items = Stock::query()->get(['id', 'current_quantity', 'alert_threshold']);

        return [
            'items'    => $items->count(),
            'low_items' => $items->filter(fn (Stock $s) => $s->is_low)->count(),
        ];
    }

    private function commerce(Carbon $from, Carbon $to): array
    {
        $weekRevenue = (float) Sale::query()
            ->whereIn('status', ['valide', 'livre'])
            ->whereDate('sale_date', '>=', $from->toDateString())
            ->whereDate('sale_date', '<=', $to->toDateString())
            ->sum('total_amount');

        $unpaid = Sale::query()->unpaid()->whereNotIn('status', ['brouillon', 'annule'])->get(['total_amount', 'paid_amount']);

        return [
            'week_revenue'   => round($weekRevenue, 2),
            'open_count'     => $unpaid->count(),
            'open_receivable' => round($unpaid->sum(fn ($s) => (float) $s->total_amount - (float) $s->paid_amount), 2),
        ];
    }

    /**
     * Techniciens du site avec leur complétion de la semaine — la comparaison
     * que le promoteur cherche vraiment : ses deux agents de Kindia et son agent
     * isolé de Kérouané, sur la même ligne de lecture.
     *
     * Réutilise TechnicianWeekService : un chiffre unique, pas un second calcul
     * qui divergerait de la fiche individuelle.
     *
     * @return array<int, array<string, mixed>>
     */
    private function team(Carbon $week): array
    {
        $service = app(TechnicianWeekService::class);

        return Employee::active()
            ->orderBy('first_name')
            ->get()
            ->map(function (Employee $employee) use ($service, $week) {
                $sheet = $service->forEmployee($employee, $week);
                $completion = collect($sheet['indicators'])->firstWhere('key', 'completion');

                return [
                    'id'         => $employee->id,
                    'name'       => trim($employee->first_name . ' ' . $employee->last_name),
                    'job_title'  => $employee->job_title,
                    'completion' => $completion['value'] ?? null,
                    'tone'       => $completion['tone'] ?? 'neutral',
                    'tasks'      => $sheet['tasks'],
                ];
            })
            // Un employé sans tâche planifiée n'a pas de semaine à comparer.
            ->filter(fn ($row) => ($row['tasks']['total'] ?? 0) > 0)
            ->values()
            ->all();
    }
}
