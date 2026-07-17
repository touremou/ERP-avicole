<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Employee;
use App\Models\TaskAssignment;
use App\Models\TaskTemplate;
use App\Services\TaskSchedulerService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class TaskController extends Controller
{
    /**
     * Farm ID courante (session multi-site).
     */
    private function farmId(): ?int
    {
        return session('current_farm_id') ?: null;
    }

    /**
     * Départements autorisés par catégorie de tâche. Empêche d'affecter une
     * tâche d'élevage à un employé d'un autre service (ex. un vendeur) — la
     * cohérence métier du planning. Une catégorie absente d'ici n'est pas
     * contrainte ; un employé sans département renseigné n'est jamais bloqué.
     */
    private const CATEGORY_DEPARTMENTS = [
        'alimentation' => ['Elevage'],
        'collecte'     => ['Elevage'],
        'nettoyage'    => ['Elevage'],
        'sante'        => ['Elevage'],
        'controle'     => ['Elevage'],
        'maintenance'  => ['Elevage', 'Logistique'],
    ];

    /**
     * Renvoie un message d'erreur si l'employé n'est pas du bon service pour
     * la catégorie de tâche, sinon null.
     */
    private function departmentMismatch(?Employee $employee, ?string $category): ?string
    {
        if (! $employee || ! $category) {
            return null;
        }

        $allowed = self::CATEGORY_DEPARTMENTS[$category] ?? null;
        if ($allowed === null || empty($employee->department) || in_array($employee->department, $allowed, true)) {
            return null;
        }

        $services = implode(' / ', $allowed);

        return "{$employee->first_name} ({$employee->department}) n'est pas du service concerné : une tâche « {$category} » revient au service {$services}. Choisissez un employé de ce service.";
    }

    public function index(Request $request, TaskSchedulerService $service)
    {
        if (Gate::denies('rh.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $date = Carbon::parse($request->input('date', now()->toDateString()));
        $view = $request->input('view', 'day');
        $filter = $request->input('filter', 'all');
        $employeeId = $request->input('employee');
        $buildingId = $request->input('building');
        $plotId = $request->input('plot');
        $category = $request->input('category');
        $priority = $request->input('priority');

        // ═══ PORTÉE RBAC : espace perso vs encadrement ═══
        // Un non-encadrant (sans admin.M) ne voit QUE ses propres tâches : on
        // verrouille le filtre employé sur sa fiche RH, quels que soient les
        // paramètres d'URL. Les liens « Mes tâches » du menu profil passent
        // ?mine=1 pour présélectionner l'utilisateur courant — y compris pour
        // un encadrant, qui peut ensuite élargir à toute l'équipe.
        $myEmployeeId = Auth::user()?->employee?->id;
        $canSeeAll    = Gate::allows('rh.M');

        if (! $canSeeAll) {
            $employeeId = $myEmployeeId;
        } elseif ($request->boolean('mine') && $myEmployeeId) {
            $employeeId = $myEmployeeId;
        }

        $activeFilters = array_filter([
            'employee' => $employeeId,
            'building' => $buildingId,
            'plot'     => $plotId,
            'category' => $category,
            'priority' => $priority,
        ]);

        // FarmScope s'applique automatiquement sur ces modèles
        $employees = Employee::where('status', 'Actif')->orderBy('first_name')->get();
        $buildings = Building::physical()->orderBy('name')->get();
        $filteredEmployee = $employeeId ? Employee::find($employeeId) : null;

        // ═══ VUE MENSUELLE ═══
        $calendarData = [];
        if ($view === 'month') {
            $startOfMonth = $date->copy()->startOfMonth();
            $endOfMonth = $date->copy()->endOfMonth();

            $monthQuery = TaskAssignment::whereBetween('scheduled_date', [$startOfMonth, $endOfMonth]);
            if ($employeeId) $monthQuery->where('employee_id', $employeeId);
            if ($buildingId) $monthQuery->where('building_id', $buildingId);
            if ($category)   $monthQuery->where('category', $category);

            $monthTasks = $monthQuery->get()->groupBy(fn($t) => $t->scheduled_date->format('Y-m-d'));

            $cursor = $startOfMonth->copy();
            while ($cursor->lte($endOfMonth)) {
                $key = $cursor->format('Y-m-d');
                $dayTasks = $monthTasks[$key] ?? collect();
                $total = $dayTasks->count();
                $done = $dayTasks->where('status', 'fait')->count();
                $calendarData[$key] = [
                    'total' => $total,
                    'done'  => $done,
                    'late'  => $dayTasks->where('status', 'en_retard')->count(),
                    'rate'  => $total > 0 ? round($done / $total * 100) : null,
                ];
                $cursor->addDay();
            }
        }

        // ═══ VUE JOURNALIÈRE ═══
        // Tri par criticité décroissante. CASE portable (MySQL + SQLite) plutôt
        // que FIELD(), qui n'existe pas en SQLite.
        $query = TaskAssignment::with(['employee', 'building', 'plot', 'template'])
            ->forDate($date)
            ->orderBy('scheduled_time')
            ->orderByRaw("CASE priority WHEN 'critique' THEN 1 WHEN 'haute' THEN 2 WHEN 'normale' THEN 3 WHEN 'basse' THEN 4 ELSE 5 END");

        if ($employeeId) $query->where('employee_id', $employeeId);
        if ($buildingId) $query->where('building_id', $buildingId);
        if ($plotId)     $query->where('plot_id', $plotId);
        if ($category)   $query->where('category', $category);
        if ($priority)   $query->where('priority', $priority);
        if ($filter === 'pending') $query->pending();
        elseif ($filter === 'done') $query->completed();

        $tasks = $query->get();
        $stats = $service->getDashboardStats($date, $this->farmId());

        $overdueQuery = TaskAssignment::overdue()->with(['employee', 'building']);
        if ($employeeId) $overdueQuery->where('employee_id', $employeeId);
        $overdueTasks = $overdueQuery->get();

        return view('tasks.index', compact(
            'tasks', 'stats', 'date', 'view', 'filter', 'employees', 'buildings',
            'overdueTasks', 'filteredEmployee', 'activeFilters', 'calendarData', 'canSeeAll'
        ));
    }

    public function generate(Request $request, TaskSchedulerService $service)
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Non autorisé.');

        $date = Carbon::parse($request->input('date', now()->toDateString()));
        $result = $service->generateForDate($date, $this->farmId());

        return back()->with('success', "{$result['created']} tâches générées pour le {$date->format('d/m/Y')}, {$result['overdue']} marquées en retard.");
    }

    public function complete(Request $request, TaskAssignment $task)
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Non autorisé.');

        $task->update([
            'status'           => 'fait',
            'completed_at'     => now(),
            'completed_by'     => Auth::id(),
            'completion_notes' => $request->input('notes'),
        ]);

        return back()->with('success', "✅ \"{$task->title}\" terminée.");
    }

    public function assign(Request $request, TaskAssignment $task)
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Non autorisé.');

        $validated = $request->validate(['employee_id' => 'required|exists:employees,id']);

        // Garde-fou disponibilité : pas d'affectation à un employé en congé à la date prévue.
        $employee = \App\Models\Employee::find($validated['employee_id']);
        $date = \Illuminate\Support\Carbon::parse($task->scheduled_date);
        if ($employee && $employee->isOnLeaveOn($date)) {
            return back()->with('error', "{$employee->first_name} est en congé le {$date->format('d/m/Y')}. Choisissez un collègue disponible.");
        }

        if ($mismatch = $this->departmentMismatch($employee, $task->category)) {
            return back()->with('error', $mismatch);
        }

        $task->update($validated);

        return back()->with('success', "Tâche assignée à {$task->fresh()->employee->first_name}.");
    }

    public function storeManual(Request $request)
    {
        if (Gate::denies('rh.C')) return back()->with('error', 'Non autorisé.');

        $validated = $request->validate([
            'title'           => 'required|string|max:255',
            'category'        => 'required|string|max:50',
            'employee_id'     => 'nullable|exists:employees,id',
            'building_id'     => 'nullable|exists:buildings,id',
            'scheduled_date'  => 'required|date',
            'scheduled_time'  => 'nullable|date_format:H:i',
            'priority'        => 'required|in:basse,normale,haute,critique',
            'description'     => 'nullable|string|max:500',
        ]);

        // Garde-fou disponibilité : pas d'affectation à un employé en congé à la date prévue.
        if (! empty($validated['employee_id'])) {
            $employee = \App\Models\Employee::find($validated['employee_id']);
            $date = \Illuminate\Support\Carbon::parse($validated['scheduled_date']);
            if ($employee && $employee->isOnLeaveOn($date)) {
                return back()->with('error', "{$employee->first_name} est en congé le {$date->format('d/m/Y')}. Choisissez un collègue disponible.")->withInput();
            }
            if ($mismatch = $this->departmentMismatch($employee, $validated['category'])) {
                return back()->with('error', $mismatch)->withInput();
            }
        }

        TaskAssignment::create(array_merge($validated, [
            'farm_id'           => $this->farmId(),
            'status'            => 'a_faire',
            'is_auto_generated' => false,
        ]));

        return back()->with('success', "Tâche \"{$validated['title']}\" créée.");
    }

    public function edit(TaskAssignment $task)
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Non autorisé.');
        if ($task->status === 'fait') return back()->with('error', 'Impossible de modifier une tâche terminée.');

        $employees = Employee::where('status', 'Actif')->orderBy('first_name')->get();
        $buildings = Building::physical()->orderBy('name')->get();

        return view('tasks.edit', compact('task', 'employees', 'buildings'));
    }

    public function update(Request $request, TaskAssignment $task)
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Non autorisé.');
        if ($task->status === 'fait') return back()->with('error', 'Impossible de modifier une tâche terminée.');

        $validated = $request->validate([
            'title'           => 'required|string|max:255',
            'category'        => 'required|string|max:50',
            'employee_id'     => 'nullable|exists:employees,id',
            'building_id'     => 'nullable|exists:buildings,id',
            'scheduled_date'  => 'required|date',
            'scheduled_time'  => 'nullable',
            'priority'        => 'required|in:basse,normale,haute,critique',
            'description'     => 'nullable|string|max:500',
            'status'          => 'nullable|in:a_faire,en_cours,annule',
        ]);

        $task->update($validated);

        return redirect()->route('tasks.index', ['date' => $task->scheduled_date->toDateString()])
            ->with('success', "Tâche \"{$task->title}\" mise à jour.");
    }

    public function destroy(TaskAssignment $task)
    {
        if (Gate::denies('rh.S')) return back()->with('error', 'Non autorisé.');

        $date = $task->scheduled_date->toDateString();
        $task->delete();

        return redirect()->route('tasks.index', ['date' => $date])->with('success', 'Tâche supprimée.');
    }

    // ─── TEMPLATES ───

    public function templates()
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Non autorisé.');

        // Templates = globaux (withoutGlobalScopes)
        $templates = TaskTemplate::withoutGlobalScopes()
            ->orderBy('category')->orderBy('scheduled_time')->get();
        $buildings = Building::physical()->orderBy('name')->get();
        $batchTypeOptions = TaskTemplate::batchTypeOptions();
        $plotTypeOptions = TaskTemplate::plotTypeOptions();

        return view('tasks.templates', compact('templates', 'buildings', 'batchTypeOptions', 'plotTypeOptions'));
    }

    public function storeTemplate(Request $request)
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Non autorisé.');

        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'category'         => 'required|string|max:50',
            'frequency'        => 'required|in:quotidien,hebdo,mensuel,ponctuel',
            'days_of_week'     => 'nullable|array',
            'days_of_week.*'   => 'integer|min:1|max:7',
            'scheduled_time'   => 'nullable',
            'duration_minutes' => 'required|integer|min:5|max:480',
            'priority'         => 'required|in:basse,normale,haute,critique',
            'per_building'     => 'nullable',
            'per_plot'         => 'nullable',
            'batch_types'      => 'nullable|array',
            'batch_types.*'    => 'string',
            'plot_types'       => 'nullable|array',
            'plot_types.*'     => 'string',
            'description'      => 'nullable|string|max:500',
        ]);

        $icons = ['alimentation' => 'fa-bowl-food', 'collecte' => 'fa-egg', 'controle' => 'fa-clipboard-check',
                   'nettoyage' => 'fa-broom', 'sante' => 'fa-heart-pulse', 'maintenance' => 'fa-wrench'];
        $icons += ['irrigation' => 'fa-droplet', 'sarclage' => 'fa-trowel', 'traitement' => 'fa-spray-can-sparkles', 'fertilisation' => 'fa-flask', 'recolte' => 'fa-basket-shopping', 'semis' => 'fa-seedling', 'controle' => 'fa-clipboard-check'];
        $colors = ['alimentation' => 'amber', 'collecte' => 'emerald', 'controle' => 'blue',
                    'nettoyage' => 'purple', 'sante' => 'rose', 'maintenance' => 'slate'];
        $colors += ['irrigation' => 'cyan', 'sarclage' => 'amber', 'traitement' => 'rose', 'fertilisation' => 'green', 'recolte' => 'emerald', 'semis' => 'lime'];

        if (isset($validated['per_building'])) {
            $targetType = 'building';
        } elseif (isset($validated['per_plot'])) {
            $targetType = 'plot';
        } else {
            $targetType = 'farm';
        }

        TaskTemplate::create([
            'name'             => $validated['name'],
            'category'         => $validated['category'],
            'frequency'        => $validated['frequency'],
            'days_of_week'     => $validated['days_of_week'] ?? null,
            'scheduled_time'   => $validated['scheduled_time'] ?? null,
            'duration_minutes' => $validated['duration_minutes'],
            'priority'         => $validated['priority'],
            'per_building'     => isset($validated['per_building']),
            'batch_types'      => !empty($validated['batch_types']) ? $validated['batch_types'] : null,
            'plot_types'       => !empty($validated['plot_types']) ? $validated['plot_types'] : null,
            'description'      => $validated['description'] ?? null,
            'icon'             => $icons[$validated['category']] ?? 'fa-circle',
            'color'            => $colors[$validated['category']] ?? 'slate',
            'target_type'      => $targetType,
            'is_active'        => true,
        ]);

        return back()->with('success', "Template \"{$validated['name']}\" créé.");
    }

    public function editTemplate(TaskTemplate $template)
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Non autorisé.');
        $batchTypeOptions = TaskTemplate::batchTypeOptions();
        return view('tasks.edit-template', compact('template', 'batchTypeOptions'));
    }

    public function updateTemplate(Request $request, TaskTemplate $template)
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Non autorisé.');

        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'category'         => 'required|string|max:50',
            'frequency'        => 'required|in:quotidien,hebdo,mensuel,ponctuel',
            'days_of_week'     => 'nullable|array',
            'days_of_week.*'   => 'integer|min:1|max:7',
            'scheduled_time'   => 'nullable',
            'duration_minutes' => 'required|integer|min:5|max:480',
            'priority'         => 'required|in:basse,normale,haute,critique',
            'per_building'     => 'nullable',
            'per_plot'         => 'nullable',
            'batch_types'      => 'nullable|array',
            'plot_types'       => 'nullable|array',
            'plot_types.*'     => 'string',
            'description'      => 'nullable|string|max:500',
        ]);

        if (isset($validated['per_building'])) {
            $targetType = 'building';
        } elseif (isset($validated['per_plot'])) {
            $targetType = 'plot';
        } else {
            $targetType = 'farm';
        }

        $template->update([
            'name'             => $validated['name'],
            'category'         => $validated['category'],
            'frequency'        => $validated['frequency'],
            'days_of_week'     => $validated['days_of_week'] ?? null,
            'scheduled_time'   => $validated['scheduled_time'] ?? null,
            'duration_minutes' => $validated['duration_minutes'],
            'priority'         => $validated['priority'],
            'per_building'     => isset($validated['per_building']),
            'batch_types'      => !empty($validated['batch_types']) ? $validated['batch_types'] : null,
            'plot_types'       => !empty($validated['plot_types']) ? $validated['plot_types'] : null,
            'description'      => $validated['description'] ?? null,
            'target_type'      => $targetType,
        ]);

        return redirect()->route('tasks.templates')->with('success', "Template \"{$template->name}\" mis à jour.");
    }

    public function destroyTemplate(TaskTemplate $template)
    {
        if (Gate::denies('rh.S')) return back()->with('error', 'Non autorisé.');

        $name = $template->name;
        $template->delete();

        return back()->with('success', "Template \"{$name}\" supprimé.");
    }

    public function toggleTemplate(TaskTemplate $template)
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Non autorisé.');

        $template->update(['is_active' => ! $template->is_active]);
        $state = $template->is_active ? 'activé' : 'désactivé';

        return back()->with('success', "\"{$template->name}\" {$state}.");
    }
}
