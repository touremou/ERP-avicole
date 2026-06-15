<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Building;
use App\Models\PlannedBatch;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Models\Protocol;
use App\Models\Provider;
use App\Models\Species;
use App\Services\PlanningService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PlanningController extends Controller
{
    public function index(Request $request, PlanningService $service)
    {
        if (Gate::denies('planning.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $from = Carbon::parse($request->input('from', now()->startOfMonth()->toDateString()));
        $to = Carbon::parse($request->input('to', now()->addMonths(6)->endOfMonth()->toDateString()));

        $plans = $service->getCalendar($from, $to);
        $occupancy = $service->getBuildingOccupancy($from, $to);
        $alerts = $service->getAlerts();
        $buildings = Building::physical()->orderBy('name')->get();

        $kpi = [
            'total_planned'   => PlannedBatch::whereNotIn('status', ['termine', 'annule'])->count(),
            'arriving_7days'  => PlannedBatch::whereIn('status', ['commande', 'planifie'])
                ->whereBetween('planned_arrival_date', [now(), now()->addDays(7)])->count(),
            'overdue_orders'  => PlannedBatch::overdue()->count(),
            'active_batches'  => Batch::active()->live()->count(),
            'total_birds'     => Batch::active()->live()->sum('current_quantity'),
        ];

        return view('planning.index', compact('plans', 'occupancy', 'alerts', 'buildings', 'from', 'to', 'kpi'));
    }

    public function create()
    {
        if (Gate::denies('planning.C')) return back()->with('error', 'Action non autorisée.');

        $buildings = Building::physical()
            ->withCount(['batches as active_count' => fn($q) => $q->active()])
            ->withSum(['batches as occupied_qty' => fn($q) => $q->active()], 'current_quantity')
            ->orderBy('name')->get();

        $providers = Provider::active()->orderBy('name')->get();
        $normModels = ProductionNorm::select('model_name', 'batch_type')->distinct()->orderBy('model_name')->get();
        $protocols = Protocol::orderBy('name')->get();
        // Types de production de toutes les espèces actives (planification multiespèces).
        $productionTypes = ProductionType::active()->with('species')->orderBy('species_id')->get();

        return view('planning.create', compact('buildings', 'providers', 'normModels', 'protocols', 'productionTypes'));
    }

    public function store(Request $request)
    {
        if (Gate::denies('planning.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'building_id'          => 'required|exists:buildings,id',
            // batch_type = slug du type de production (multiespèces) ; plus de
            // liste figée volaille. species_id/production_type_id portent l'espèce.
            'batch_type'           => 'required|string|max:50',
            'species_id'           => 'nullable|exists:species,id',
            'production_type_id'   => 'nullable|exists:production_types,id',
            'model_name'           => 'nullable|string|max:100',
            'planned_quantity'     => 'required|integer|min:1',
            'planned_arrival_date' => 'required|date|after_or_equal:today',
            'provider_id'          => 'nullable|exists:providers,id',
            'protocol_id'          => 'nullable|exists:protocols,id',
            'notes'                => 'nullable|string|max:1000',
        ]);

        $arrivalDate = Carbon::parse($validated['planned_arrival_date']);
        // Cycle issu du type de production choisi (sinon repli legacy par slug).
        $cycleOverride = ($validated['production_type_id'] ?? null)
            ? ProductionType::find($validated['production_type_id'])?->cycle_days_default
            : null;
        $dates = PlannedBatch::calculateDates($validated['batch_type'], $arrivalDate, $cycleOverride);

        $building = Building::find($validated['building_id']);

        // Compatibilité bâtiment / espèce (cf. Species::buildingIsCompatible,
        // partagée avec StoreBatchRequest/UpdateBatchRequest/TransferBatchRequest) :
        // une planification doit cibler un bâtiment adapté à l'espèce visée.
        $species = ($validated['species_id'] ?? null) ? Species::find($validated['species_id']) : null;
        if (! Species::buildingIsCompatible($building, $species, $validated['batch_type'])) {
            return back()->withErrors([
                'building_id' => "Incompatibilité : type cible '{$validated['batch_type']}', bâtiment de type '{$building->type}'."
            ])->withInput();
        }

        $occupiedQty = $building->batches()->active()->sum('current_quantity');
        $available = $building->capacity - $occupiedQty;

        if ($validated['planned_quantity'] > $available) {
            return back()->withErrors([
                'planned_quantity' => "Capacité insuffisante : {$building->name} — {$available} places disponibles."
            ])->withInput();
        }

        $hasConflict = PlannedBatch::where('building_id', $validated['building_id'])
            ->whereNotIn('status', ['termine', 'annule'])
            ->where('planned_arrival_date', '<=', $dates['sanitary_void_end'])
            ->where(fn($q) => $q->where('sanitary_void_end', '>=', $arrivalDate)
                                ->orWhere('planned_end_date', '>=', $arrivalDate))
            ->exists();

        if ($hasConflict) {
            return back()->withErrors(['building_id' => "Conflit de dates sur {$building->name}."])->withInput();
        }

        $df = setting('general.date_format', 'd/m/Y');

        PlannedBatch::create(array_merge($validated, $dates, [
            'created_by' => Auth::id(),
            'farm_id'    => session('current_farm_id'),
        ]));

        return redirect()->route('planning.index')
            ->with('success', "Bande planifiée — {$building->name}, arrivée {$arrivalDate->format($df)}, commande avant {$dates['chick_order_deadline']->format($df)}.");
    }

    public function show(PlannedBatch $plan)
    {
        if (Gate::denies('planning.L')) return back()->with('error', 'Accès restreint.');
        $plan->load(['building', 'provider', 'creator', 'actualBatch']);
        return view('planning.show', compact('plan'));
    }

    public function updateStatus(Request $request, PlannedBatch $plan)
    {
        if (Gate::denies('planning.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'status'             => 'required|in:planifie,commande,en_cours,termine,annule',
            'qty_alive'          => 'nullable|integer|min:1',
            'qty_dead'           => 'nullable|integer|min:0',
            'buy_price_per_unit' => 'nullable|numeric|min:0',
            'employee_id'        => 'nullable|exists:employees,id',
        ]);

        $newStatus = $validated['status'];
        $allowed = match($plan->status) {
            'planifie'  => ['commande', 'annule'],
            'commande'  => ['en_cours', 'annule'],
            'en_cours'  => ['termine'],
            default     => [],
        };

        if (! in_array($newStatus, $allowed)) {
            return back()->with('error', "Transition {$plan->status} → {$newStatus} non autorisée.");
        }

        return DB::transaction(function () use ($plan, $validated, $newStatus) {

            // ═══ EN_COURS → Créer le lot réel ═══
            if ($newStatus === 'en_cours' && ! $plan->actual_batch_id) {
                $qtyAlive = $validated['qty_alive'] ?? $plan->planned_quantity;
                $qtyDead = $validated['qty_dead'] ?? 0;
                $totalReceived = $qtyAlive + $qtyDead;
                $employeeId = $validated['employee_id'] ?? null;

                if (! $employeeId) {
                    return back()->withErrors(['employee_id' => 'Responsable obligatoire.'])->withInput();
                }

                // Cycle : priorité au type de production (multiespèces), repli legacy.
                $cycleDays = (int) ($plan->productionType?->cycle_days_default
                    ?? setting("elevage.cycle_{$plan->batch_type}", 42));
                $batchPrefix = setting("elevage.batch_prefix_{$plan->batch_type}", 'LOT');

                $batch = Batch::create([
                    'uuid'                   => (string) Str::uuid(),
                    'code'                   => $batchPrefix . '-' . now()->format('Ymd-His'),
                    'type'                   => $plan->batch_type,
                    // Propagation de l'espèce : un lot issu du planning est désormais
                    // pleinement multiespèces (plus de lot « sans espèce »).
                    'species_id'             => $plan->species_id,
                    'production_type_id'     => $plan->production_type_id,
                    'model_name'             => $plan->model_name,
                    'building_id'            => $plan->building_id,
                    'provider_id'            => $plan->provider_id,
                    'employee_id'            => $employeeId,
                    'initial_quantity'       => $totalReceived,
                    'current_quantity'       => $qtyAlive,
                    'qty_alive'              => $qtyAlive,
                    'qty_dead'               => $qtyDead,
                    'arrival_mortality_rate' => $totalReceived > 0 ? round(($qtyDead / $totalReceived) * 100, 2) : 0,
                    'arrival_date'           => now()->toDateString(),
                    'expected_end_date'      => now()->addDays($cycleDays),
                    'buy_price_per_unit'     => $validated['buy_price_per_unit'] ?? 0,
                    'total_acquisition_cost' => $totalReceived * ($validated['buy_price_per_unit'] ?? 0),
                    'status'                 => 'Actif',
                    'chick_state'            => 'Normal',
                    'protocol_id'            => $plan->protocol_id ?? null,
                    'is_synced'              => true,
                    'farm_id'                => $plan->farm_id ?? session('current_farm_id'),
                ]);

                $plan->update(['status' => 'en_cours', 'actual_batch_id' => $batch->id]);

                return redirect()->route('batches.show', $batch)
                    ->with('success', "Lot {$batch->code} créé — {$qtyAlive} sujets dans {$plan->building->name}.");
            }

            // ═══ TERMINÉ ═══
            if ($newStatus === 'termine' && $plan->actual_batch_id) {
                $batch = Batch::find($plan->actual_batch_id);
                if ($batch?->status === 'Actif') {
                    $batch->update(['status' => 'Terminé', 'closing_date' => now()]);
                }
            }

            // ═══ ANNULÉ ═══
            if ($newStatus === 'annule' && $plan->actual_batch_id) {
                $batch = Batch::find($plan->actual_batch_id);
                if ($batch?->status === 'Actif' && $batch->current_quantity === $batch->initial_quantity) {
                    $batch->update(['status' => 'Annulé']);
                }
            }

            $plan->update(['status' => $newStatus]);
            return back()->with('success', "Statut : {$newStatus}.");
        });
    }

    public function activateForm(PlannedBatch $plan)
    {
        if (Gate::denies('planning.M')) return back()->with('error', 'Action non autorisée.');
        if ($plan->actual_batch_id) return back()->with('error', 'Un lot actif existe déjà.');

        $plan->load(['building', 'provider']);
        $employees = \App\Models\Employee::where('status', 'actif')->orderBy('first_name')->get();

        return view('planning.activate', compact('plan', 'employees'));
    }

    public function destroy(PlannedBatch $plan)
    {
        if (Gate::denies('planning.S')) return back()->with('error', 'Suppression réservée aux administrateurs.');
        if ($plan->actual_batch_id) return back()->with('error', 'Lot réel lié — suppression impossible.');

        $plan->delete();
        return redirect()->route('planning.index')->with('success', 'Planification supprimée.');
    }
}
