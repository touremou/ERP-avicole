<?php

namespace App\Http\Controllers;

use App\Models\Dispatch;
use App\Models\Reception;
use App\Models\DiscrepancyReport;
use App\Models\Client;
use App\Models\Stock;
use App\Models\Batch;
use App\Actions\Dispatch\CreateDispatch;
use App\Actions\Dispatch\ValidateReception;
use App\Services\ReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
        $batches = Batch::where('status', 'Actif')->with(['building', 'species'])->get();

        return view('dispatches.create', compact('stocks', 'batches'));
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
            'sale_id'                 => 'nullable|exists:sales,id',
            'notes'                   => 'nullable|string|max:1000',
            'items'                   => 'required|array|min:1',
            // Taxonomie multiespèces (alignée sur les ventes).
            'items.*.product_type'    => 'required|in:oeufs,animal_vif,carcasse,lait,fumier,aliment,materiel,autre,volaille_vivante,volaille_abattue',
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

        $dispatch->load(['items', 'dispatcher', 'reception.items.dispatchItem', 'reception.receiver', 'discrepancyReport']);

        return view('dispatches.show', compact('dispatch'));
    }

    // ─── RÉCEPTION ───

    public function showReceptionForm(Dispatch $dispatch)
    {
        if (Gate::denies('logistique.C')) return back()->with('error', 'Action non autorisée.');

        if ($dispatch->reception()->exists()) {
            return redirect()->route('dispatches.show', $dispatch)
                ->with('error', 'Cette expédition a déjà été réceptionnée.');
        }

        $dispatch->load('items');

        return view('dispatches.reception', compact('dispatch'));
    }

    public function storeReception(Request $request, Dispatch $dispatch, ValidateReception $action)
    {
        if (Gate::denies('logistique.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
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
