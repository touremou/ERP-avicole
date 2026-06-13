<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
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

        // Bâtiments et employés = filtrés par ferme
        $buildingQuery = Building::whereHas('batches', fn($q) => $q->active());
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
                                ->whereIn('type', $tpl->batch_types)
                                ->exists();
                            if (! $hasBatchType) continue;
                        }

                        if ($this->alreadyExists($tpl, $date, $building->id, $farmId)) { $skipped++; continue; }

                        $employee = $this->findBestEmployee($building, $employees, $date);

                        TaskAssignment::create([
                            'farm_id'          => $farmId ?? $building->farm_id ?? null,
                            'task_template_id' => $tpl->id,
                            'employee_id'      => $employee?->id,
                            'title'            => $tpl->name . ' — ' . $building->name,
                            'description'      => $tpl->description,
                            'category'         => $tpl->category,
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
                } else {
                    if ($this->alreadyExists($tpl, $date, null, $farmId)) { $skipped++; continue; }

                    TaskAssignment::create([
                        'farm_id'          => $farmId,
                        'task_template_id' => $tpl->id,
                        'title'            => $tpl->name,
                        'description'      => $tpl->description,
                        'category'         => $tpl->category,
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
        if (Schema::hasColumn('employees', 'assigned_building_id')) {
            $assigned = $employees->where('assigned_building_id', $building->id)->first();
            if ($assigned) return $assigned;
        }

        $batch = Batch::where('building_id', $building->id)->active()->first();
        if ($batch && $batch->employee_id) {
            $emp = $employees->where('id', $batch->employee_id)->first();
            if ($emp) return $emp;
        }

        return $employees->sortBy(fn($emp) =>
            TaskAssignment::where('employee_id', $emp->id)
                ->where('scheduled_date', $date->toDateString())
                ->count()
        )->first();
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
