<?php

namespace App\Http\Controllers;

use App\Models\Dispatch;
use App\Models\Employee;
use App\Models\Module;
use App\Models\ModulePermission;
use App\Models\Reception;
use App\Models\DiscrepancyReport;
use App\Models\Client;
use App\Models\Stock;
use App\Models\Batch;
use App\Actions\Dispatch\CreateDispatch;
use App\Actions\Dispatch\ValidateReception;
use App\Services\ReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class DispatchController extends Controller
{
    // ─── EXPÉDITIONS ───

    public function index(Request $request)
    {
        if (Gate::denies('logistique.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $query = Dispatch::with(['dispatcher', 'reception']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $dispatches = $query->latest('dispatch_date')->paginate((int) setting('general.items_per_page', 20));

        $stats = [
            'pending'   => Dispatch::pending()->count(),
            'today'     => Dispatch::whereDate('dispatch_date', today())->count(),
            'in_dispute' => Reception::where('status', 'litige')->count(),
        ];

        return view('dispatches.index', compact('dispatches', 'stats'));
    }

    public function create()
    {
        if (Gate::denies('logistique.C')) return back()->with('error', 'Action non autorisée.');

        $stocks  = Stock::where('current_quantity', '>', 0)->get();
        // Tous les lots actifs, toutes espèces, sont expédiables sur pied.
        $batches = Batch::active()->live()->with(['building', 'species'])->get();

        // Récepteurs proposables : employés disposant d'un compte ACTIF avec
        // accès au module logistique (ils pourront se connecter pour valider).
        $receivers = $this->candidateReceivers();

        return view('dispatches.create', compact('stocks', 'batches', 'receivers'));
    }

    /**
     * Employés rattachés à un compte utilisateur actif ayant l'accès logistique
     * (lecture au minimum) — candidats au rôle de récepteur désigné. La valeur
     * retournée pour le <select> est l'id du COMPTE (users.id), car valider une
     * réception suppose de se connecter (comparaison Auth::id()).
     */
    private function candidateReceivers()
    {
        $module = Module::where('slug', 'logistique')->first();
        if (! $module) return collect();

        $roleIds = ModulePermission::where('module_id', $module->id)
            ->where('can_read', true)
            ->pluck('role_id');

        return Employee::query()
            ->whereNotNull('user_id')
            ->whereHas('user', fn ($q) => $q->whereIn('role_id', $roleIds)
                ->where(fn ($w) => $w->whereNull('is_active')->orWhere('is_active', true)))
            ->with('user:id,name')
            ->orderBy('first_name')
            ->get();
    }

    public function store(Request $request, CreateDispatch $action)
    {
        if (Gate::denies('logistique.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'driver_name'             => 'required|string|max:255',
            'driver_phone'            => 'nullable|string|max:30',
            'vehicle_plate'           => 'nullable|string|max:20',
            'dispatch_date'           => 'required|date',
            'dispatch_time'           => 'nullable|date_format:H:i',
            'destination'             => 'required|string|max:255',
            'intended_receiver_id'    => 'nullable|exists:users,id',
            'sale_id'                 => 'nullable|exists:sales,id',
            'notes'                   => 'nullable|string|max:1000',
            'items'                   => 'required|array|min:1',
            // Taxonomie multiespèces (alignée sur les ventes).
            'items.*.product_type'    => 'required|in:oeufs,animal_vif,carcasse,lait,fumier,aliment,produits_finis,materiel,autre,volaille_vivante,volaille_abattue',
            'items.*.product_name'    => 'required|string|max:255',
            'items.*.product_id'      => 'nullable|integer',
            'items.*.batch_id'        => 'nullable|integer|exists:batches,id',
            'items.*.quantity'        => 'required|numeric|min:0.01',
            'items.*.unit'            => 'required|in:alveole,unite,kg,piece,sac,voyage,tete,litre',
            'items.*.condition'       => 'nullable|in:bon,moyen,fragile',
        ]);

        try {
            $dispatch = $action->execute($validated);
            return redirect()->route('dispatches.show', $dispatch)
                ->with('success', "Expédition {$dispatch->dispatch_number} enregistrée. Chauffeur: {$dispatch->driver_name}.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function show(Dispatch $dispatch)
    {
        if (Gate::denies('logistique.L')) return back()->with('error', 'Accès restreint.');

        $dispatch->load(['items', 'dispatcher', 'intendedReceiver', 'reception.items.dispatchItem', 'reception.receiver', 'discrepancyReport']);

        return view('dispatches.show', compact('dispatch'));
    }

    // ─── RÉCEPTION ───

    /**
     * Peut réceptionner : le récepteur DÉSIGNÉ à l'expédition, OU un responsable
     * logistique.M en secours. (L'anti-fraude expéditeur ≠ récepteur reste
     * appliquée dans ValidateReception.)
     */
    private function canReceive(Dispatch $dispatch): bool
    {
        return Gate::allows('logistique.M')
            || ($dispatch->intended_receiver_id !== null && $dispatch->intended_receiver_id === Auth::id());
    }

    public function showReceptionForm(Dispatch $dispatch)
    {
        if (! $this->canReceive($dispatch)) {
            return back()->with('error', "Réception réservée au récepteur désigné ou à un responsable logistique (droit M).");
        }

        if ($dispatch->reception()->exists()) {
            return redirect()->route('dispatches.show', $dispatch)
                ->with('error', 'Cette expédition a déjà été réceptionnée.');
        }

        $dispatch->load('items');

        return view('dispatches.reception', compact('dispatch'));
    }

    public function storeReception(Request $request, Dispatch $dispatch, ValidateReception $action)
    {
        if (! $this->canReceive($dispatch)) {
            return back()->with('error', "Réception réservée au récepteur désigné ou à un responsable logistique (droit M).");
        }

        $validator = Validator::make($request->all(), [
            'reception_date'              => 'required|date',
            'reception_time'              => 'nullable|date_format:H:i',
            'notes'                       => 'nullable|string|max:1000',
            'items'                       => 'required|array|min:1',
            'items.*.dispatch_item_id'    => 'required|exists:dispatch_items,id',
            'items.*.quantity_received'   => 'required|numeric|min:0',
            'items.*.quantity_damaged'    => 'nullable|numeric|min:0',
            'items.*.condition'           => 'nullable|in:bon,endommage,suspect',
            'items.*.notes'              => 'nullable|string|max:500',
        ]);

        // COHÉRENCE QUANTITÉS : on ne peut pas réceptionner plus que ce qui a
        // quitté la ferme. Pour chaque ligne, reçu + endommagé ≤ expédié
        // (le manquant comble le solde). Sans ce contrôle, le rapport d'écart
        // afficherait des totaux reçus/endommagés supérieurs à l'expédié.
        $validator->after(function ($validator) use ($request, $dispatch) {
            $dispatchItems = $dispatch->items()->get()->keyBy('id');

            foreach ((array) $request->input('items', []) as $i => $line) {
                $di = $dispatchItems->get($line['dispatch_item_id'] ?? null);
                if (! $di) continue;

                $received = (float) ($line['quantity_received'] ?? 0);
                $damaged  = (float) ($line['quantity_damaged'] ?? 0);

                if ($received + $damaged > (float) $di->quantity_dispatched + 1e-6) {
                    $validator->errors()->add(
                        "items.{$i}.quantity_damaged",
                        "« {$di->product_name} » : reçu + endommagé (" . ($received + $damaged) . ") " .
                        "dépasse la quantité expédiée ({$di->quantity_dispatched} {$di->unit})."
                    );
                }
            }
        });

        $validated = $validator->validate();

        try {
            $reception = $action->execute($dispatch, $validated);

            $message = $reception->has_discrepancy
                ? "Réception enregistrée avec ÉCARTS DÉTECTÉS. Un rapport a été généré."
                : "Réception validée — aucun écart.";

            return redirect()->route('dispatches.show', $dispatch)->with(
                $reception->has_discrepancy ? 'warning' : 'success',
                $message
            );
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    // ─── RAPPORTS D'ÉCART ───

    public function discrepancies(Request $request)
    {
        if (Gate::denies('logistique.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $query = DiscrepancyReport::with(['dispatch', 'reception', 'reporter']);

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('resolution')) {
            $query->where('resolution', $request->resolution);
        }

        $reports = $query->latest()->paginate((int) setting('general.items_per_page', 20));

        $stats = [
            'total_open'     => DiscrepancyReport::where('resolution', 'en_cours')->count(),
            'total_critical' => DiscrepancyReport::where('severity', 'critique')->where('resolution', 'en_cours')->count(),
            'total_missing'  => DiscrepancyReport::where('resolution', 'en_cours')->sum('total_missing'),
        ];

        return view('dispatches.discrepancies', compact('reports', 'stats'));
    }

    public function resolveDiscrepancy(Request $request, DiscrepancyReport $report, ReconciliationService $service)
    {
        if (Gate::denies('logistique.S')) return back()->with('error', 'Résolution réservée aux administrateurs.');

        $validated = $request->validate([
            'resolution'       => 'required|in:justifie,injustifie,enquete',
            'resolution_notes' => 'required|string|max:2000',
        ]);

        try {
            $service->resolve($report, $validated['resolution'], $validated['resolution_notes']);
            return back()->with('success', 'Rapport résolu.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
