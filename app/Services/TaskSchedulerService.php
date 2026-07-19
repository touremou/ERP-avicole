<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Building;
use App\Models\CropCycle;
use App\Models\CropSpecies;
use App\Models\Employee;
use App\Models\Plot;
use App\Models\TaskAssignment;
use App\Models\TaskTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TaskSchedulerService
{
    /**
     * Génère les tâches pour une date et une ferme donnée.
     *
     * @param Carbon   $date
     * @param int|null $farmId  Si null, génère pour TOUTES les fermes (cron)
     */
    public function generateForDate(Carbon $date, ?int $farmId = null): array
    {
        // Templates = globaux (pas de farm_id)
        $templates = TaskTemplate::withoutGlobalScopes()->where('is_active', true)->get();

        // Bâtiments et employés = filtrés par ferme.
        // On exclut les bâtiments virtuels (cf. Building::physical) et on
        // n'exige que des lots RÉELS actifs (->live), pour qu'aucun bâtiment
        // ni lot virtuel de traçabilité ne génère de tâches.
        $buildingQuery = Building::physical()
            ->whereHas('batches', fn($q) => $q->active()->live());
        $employeeQuery = Employee::where('status', 'Actif');

        if ($farmId && Schema::hasColumn('buildings', 'farm_id')) {
            $buildingQuery->where('farm_id', $farmId);
        }
        if ($farmId && Schema::hasColumn('employees', 'farm_id')) {
            $employeeQuery->where('farm_id', $farmId);
        }

        $activeBuildings = $buildingQuery->get();
        $employees = $employeeQuery->get();

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($date, $farmId, $templates, $activeBuildings, $employees, &$created, &$skipped) {
            foreach ($templates as $tpl) {
                if (! $tpl->shouldRunOnDay($date)) continue;

                if ($tpl->per_building) {
                    foreach ($activeBuildings as $building) {
                        if ($tpl->batch_types) {
                            $hasBatchType = Batch::where('building_id', $building->id)
                                ->active()
                                ->live()
                                ->whereHas('productionType', fn ($q) => $q->whereIn('slug', $tpl->batch_types))
                                ->exists();
                            if (! $hasBatchType) continue;
                        }

                        if ($this->alreadyExists($tpl, $date, $building->id, $farmId)) { $skipped++; continue; }

                        $employee = $this->findBestEmployee($building, $employees, $date);

                        TaskAssignment::create([
                            'farm_id'          => $farmId ?? $building->farm_id ?? null,
                            'task_template_id' => $tpl->id,
                            // Pool : pas de titulaire — le premier qui la prend se l'attribue.
                            'employee_id'      => $tpl->is_pool ? null : $employee?->id,
                            'is_pool'          => $tpl->is_pool,
                            'title'            => $tpl->name . ' — ' . $building->name,
                            'description'      => $tpl->description,
                            'category'         => $tpl->category,
                            'proof_type'       => $tpl->proof_type ?? 'aucune',
                            'proof_label'      => $tpl->proof_label,
                            'proof_unit'       => $tpl->proof_unit,
                            'building_id'      => $building->id,
                            'scheduled_date'   => $date,
                            'scheduled_time'   => $tpl->scheduled_time,
                            'duration_minutes' => $tpl->duration_minutes,
                            'priority'         => $tpl->priority,
                            'status'           => 'a_faire',
                            'is_auto_generated' => true,
                        ]);
                        $created++;
                    }
                } elseif ($tpl->target_type === 'plot') {
                    // Generate one task per active plot (plots with in-progress crop cycles).
                    $plotQuery = Plot::where('status', Plot::STATUS_EN_CULTURE);
                    if ($farmId && Schema::hasColumn('plots', 'farm_id')) {
                        $plotQuery->where('farm_id', $farmId);
                    }
                    $activePlots = $plotQuery->with(['cropCycles' => fn($q) => $q->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)])->get();

                    foreach ($activePlots as $plot) {
                        // Filter by plot_types if set (match against CropSpecies type via crop_name).
                        if ($tpl->plot_types) {
                            $hasMatchingCrop = $plot->cropCycles->contains(function ($cycle) use ($tpl) {
                                // We match on the CropSpecies type if species exists, else pass.
                                $species = CropSpecies::where('name', $cycle->crop_name)->first();
                                return $species && in_array($species->type, $tpl->plot_types);
                            });
                            if (!$hasMatchingCrop) continue;
                        }

                        if ($this->alreadyExistsForPlot($tpl, $date, $plot->id, $farmId)) { $skipped++; continue; }

                        $employee = $this->findBestEmployeeForPlot($plot, $employees, $date);

                        TaskAssignment::create([
                            'farm_id'           => $farmId ?? $plot->farm_id ?? null,
                            'task_template_id'  => $tpl->id,
                            'employee_id'       => $tpl->is_pool ? null : $employee?->id,
                            'is_pool'           => $tpl->is_pool,
                            'title'             => $tpl->name . ' — ' . $plot->name,
                            'description'       => $tpl->description,
                            'category'          => $tpl->category,
                            'proof_type'        => $tpl->proof_type ?? 'aucune',
                            'proof_label'       => $tpl->proof_label,
                            'proof_unit'        => $tpl->proof_unit,
                            'plot_id'           => $plot->id,
                            'scheduled_date'    => $date,
                            'scheduled_time'    => $tpl->scheduled_time,
                            'duration_minutes'  => $tpl->duration_minutes,
                            'priority'          => $tpl->priority,
                            'status'            => 'a_faire',
                            'is_auto_generated' => true,
                        ]);
                        $created++;
                    }
                } else {
                    if ($this->alreadyExists($tpl, $date, null, $farmId)) { $skipped++; continue; }

                    TaskAssignment::create([
                        'farm_id'          => $farmId,
                        'task_template_id' => $tpl->id,
                        'is_pool'          => $tpl->is_pool,
                        'title'            => $tpl->name,
                        'description'      => $tpl->description,
                        'category'         => $tpl->category,
                        'proof_type'       => $tpl->proof_type ?? 'aucune',
                        'proof_label'      => $tpl->proof_label,
                        'proof_unit'       => $tpl->proof_unit,
                        'scheduled_date'   => $date,
                        'scheduled_time'   => $tpl->scheduled_time,
                        'duration_minutes' => $tpl->duration_minutes,
                        'priority'         => $tpl->priority,
                        'status'           => 'a_faire',
                        'is_auto_generated' => true,
                    ]);
                    $created++;
                }
            }
        });

        // Marquer en retard (jours précédents, même ferme)
        $overdueQuery = TaskAssignment::where('status', 'a_faire')
            ->where('scheduled_date', '<', $date->toDateString());
        if ($farmId && Schema::hasColumn('task_assignments', 'farm_id')) {
            $overdueQuery->where('farm_id', $farmId);
        }
        $overdue = $overdueQuery->update(['status' => 'en_retard']);

        Log::info("Tasks [{$farmId}] {$date->format('d/m')}: {$created} created, {$skipped} skipped, {$overdue} overdue");

        return ['created' => $created, 'skipped' => $skipped, 'overdue' => $overdue];
    }

    private function alreadyExists(TaskTemplate $tpl, Carbon $date, ?int $buildingId, ?int $farmId): bool
    {
        $q = TaskAssignment::where('task_template_id', $tpl->id)
            ->where('scheduled_date', $date->toDateString());

        if ($buildingId) $q->where('building_id', $buildingId);
        else $q->whereNull('building_id');

        if ($farmId && Schema::hasColumn('task_assignments', 'farm_id')) {
            $q->where('farm_id', $farmId);
        }

        return $q->exists();
    }

    private function findBestEmployee(Building $building, $employees, Carbon $date): ?Employee
    {
        // Garde-fou disponibilité : on écarte d'emblée les employés en congé
        // approuvé à cette date — on n'auto-assigne jamais une tâche à un absent.
        $available = $employees->reject(fn ($emp) => $emp->isOnLeaveOn($date))->values();
        if ($available->isEmpty()) return null;

        // 1. GARDIEN DÉDIÉ DU BÂTIMENT (configuration opérationnelle explicite) :
        //    un bâtiment confié à un agent (assigned_building_id) reste sous sa
        //    responsabilité, quelle que soit sa charge — choix d'organisation
        //    assumé, distinct de la répartition automatique ci-dessous.
        if (Schema::hasColumn('employees', 'assigned_building_id')) {
            $keeper = $available->firstWhere('assigned_building_id', $building->id);
            if ($keeper) return $keeper;
        }

        // 2. RÉPARTITION DE CHARGE (équité) : à défaut de gardien dédié, on
        //    retient l'employé le MOINS chargé ce jour-là. À charge égale, on
        //    préfère le responsable du lot présent dans le bâtiment (continuité
        //    du suivi), puis l'ordre stable.
        //
        //    Le tri par charge est PRIORITAIRE sur la responsabilité du lot :
        //    sans cela, toutes les tâches retombaient sur le responsable des
        //    lots (souvent l'agent qui a créé les bandes), en ignorant la
        //    disponibilité des autres employés — exactement le défaut signalé.
        $batch = Batch::where('building_id', $building->id)->active()->live()->first();
        $batchEmployeeId = $batch?->employee_id;

        return $available
            ->sortBy(function ($emp) use ($date, $batchEmployeeId) {
                // whereDate (et non une égalité de chaîne) : la colonne est
                // castée en datetime (« …00:00:00 »), une comparaison brute à
                // « Y-m-d » ne matchait jamais → la charge ressortait toujours à
                // 0 et la répartition était inopérante.
                $load = TaskAssignment::whereDate('scheduled_date', $date->toDateString())
                    ->where('employee_id', $emp->id)
                    ->count();

                // Clé composite : charge du jour ×10 (critère principal) + bonus
                // de continuité (0 pour le responsable du lot, 1 sinon).
                return $load * 10 + ($emp->id === $batchEmployeeId ? 0 : 1);
            })
            ->first();
    }

    private function alreadyExistsForPlot(TaskTemplate $tpl, Carbon $date, int $plotId, ?int $farmId): bool
    {
        $q = TaskAssignment::where('task_template_id', $tpl->id)
            ->where('scheduled_date', $date->toDateString())
            ->where('plot_id', $plotId);
        if ($farmId && Schema::hasColumn('task_assignments', 'farm_id')) {
            $q->where('farm_id', $farmId);
        }
        return $q->exists();
    }

    private function findBestEmployeeForPlot(Plot $plot, $employees, Carbon $date): ?Employee
    {
        $available = $employees->reject(fn ($emp) => $emp->isOnLeaveOn($date))->values();
        if ($available->isEmpty()) return null;

        // Prefer the employee assigned to the most recent active crop cycle on this plot.
        $cycleEmployeeId = $plot->cropCycles
            ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
            ->sortByDesc('planting_date')
            ->first()?->employee_id;

        return $available
            ->sortBy(function ($emp) use ($date, $cycleEmployeeId) {
                $load = TaskAssignment::whereDate('scheduled_date', $date->toDateString())
                    ->where('employee_id', $emp->id)
                    ->count();
                return $load * 10 + ($emp->id === $cycleEmployeeId ? 0 : 1);
            })
            ->first();
    }

    /**
     * Stats dashboard — filtrées par ferme si applicable.
     */
    public function getDashboardStats(Carbon $date, ?int $farmId = null): array
    {
        $query = TaskAssignment::forDate($date);
        if ($farmId && Schema::hasColumn('task_assignments', 'farm_id')) {
            $query->where('farm_id', $farmId);
        }
        $tasks = $query->get();

        $overdueQuery = TaskAssignment::overdue();
        if ($farmId && Schema::hasColumn('task_assignments', 'farm_id')) {
            $overdueQuery->where('farm_id', $farmId);
        }

        return [
            'total'       => $tasks->count(),
            'done'        => $tasks->where('status', 'fait')->count(),
            'pending'     => $tasks->whereIn('status', ['a_faire'])->count(),
            'overdue'     => $overdueQuery->count(),
            'rate'        => $tasks->count() > 0 ? round($tasks->where('status', 'fait')->count() / $tasks->count() * 100) : 0,
            'by_category' => $tasks->groupBy('category')->map->count(),
            'by_employee' => $tasks->groupBy('employee_id')->map(fn($t) => [
                'total' => $t->count(), 'done' => $t->where('status', 'fait')->count(),
            ]),
        ];
    }
}
