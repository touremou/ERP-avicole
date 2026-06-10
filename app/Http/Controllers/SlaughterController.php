<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Client;
use App\Models\CuttingSession;
use App\Models\FinishedProduct;
use App\Models\SlaughterOrder;
use App\Models\Transformation;
use App\Services\SlaughterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class SlaughterController extends Controller
{
    // ──────────────────────────────────────────────
    // DASHBOARD ABATTOIR
    // ──────────────────────────────────────────────

    public function dashboard(SlaughterService $service)
    {
        if (Gate::denies('abattoir.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        // Application du paramètre dynamique pour la période des KPI (fallback 30 jours)
        $kpi = $service->getKPI((int) setting('abattoir.kpi_days', 30));
        
        $pendingOrders = SlaughterOrder::pending()->with(['batch.building', 'requester'])->latest('planned_date')->get();
        
        // Application du paramètre dynamique pour la limite d'affichage
        $recentResults = SlaughterOrder::where('status', 'termine')
            ->with(['batch', 'result'])
            ->latest('actual_date')
            ->take((int) setting('general.items_per_page', 10))
            ->get();
            
        $finishedProducts = FinishedProduct::where('current_quantity_kg', '>', 0)->get();
        $expiring = FinishedProduct::expiringSoon()->get();

        return view('slaughter.dashboard', compact('kpi', 'pendingOrders', 'recentResults', 'finishedProducts', 'expiring'));
    }

    // ──────────────────────────────────────────────
    // ORDRES D'ABATTAGE
    // ──────────────────────────────────────────────

    public function createOrder()
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        // Tous les lots actifs sont éligibles à l'abattage/la transformation,
        // quelle que soit l'espèce (volaille, ruminants, porcins, lapins...).
        $batches = Batch::where('status', 'Actif')
            ->where('current_quantity', '>', 0)
            ->with('building')
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        $clients = Client::active()->orderBy('name')->get();

        return view('slaughter.create-order', compact('batches', 'clients'));
    }

    public function storeOrder(Request $request)
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'batch_id'         => 'required|exists:batches,id',
            'planned_date'     => 'required|date',
            'planned_quantity' => 'required|integer|min:1',
            'client_id'        => 'nullable|exists:clients,id',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $batch = Batch::findOrFail($validated['batch_id']);
        if ($batch->current_quantity < $validated['planned_quantity']) {
            return back()->withErrors([
                'planned_quantity' => "Le lot {$batch->code} n'a que {$batch->current_quantity} sujets (demandé: {$validated['planned_quantity']})."
            ])->withInput();
        }

        SlaughterOrder::create(array_merge($validated, [
            'order_number' => SlaughterOrder::generateNumber(), // La gestion du préfixe se fera dans le Model
            'requested_by' => Auth::id(),
        ]));

        return redirect()->route('slaughter.dashboard')
            ->with('success', "Ordre d'abattage créé — {$validated['planned_quantity']} sujets du lot {$batch->code}.");
    }

    // ──────────────────────────────────────────────
    // EXÉCUTION ABATTAGE
    // ──────────────────────────────────────────────

    public function showExecuteForm(SlaughterOrder $order)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');
        $order->load('batch.building');
        return view('slaughter.execute', compact('order'));
    }

    public function executeSlaughter(Request $request, SlaughterOrder $order, SlaughterService $service)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'actual_quantity'         => 'required|integer|min:1',
            'total_live_weight_kg'    => 'required|numeric|min:0.1',
            'total_carcass_weight_kg' => 'required|numeric|min:0.1',
            'condemned_count'         => 'nullable|integer|min:0',
            'condemned_reason'        => 'nullable|string|max:500',
            'execution_date'          => 'required|date',
            'inspector_notes'         => 'nullable|string|max:1000',
        ]);

        try {
            $result = $service->executeSlaughter($order, $validated);

            return redirect()->route('slaughter.dashboard')
                ->with('success', "Abattage {$order->order_number} terminé — Rendement carcasse: {$result->carcass_yield_percent}%.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    // ──────────────────────────────────────────────
    // DÉCOUPE
    // ──────────────────────────────────────────────

    public function showCuttingForm(SlaughterOrder $order)
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');
        $order->load(['result', 'cuttingSessions.products']);
        return view('slaughter.cutting', compact('order'));
    }

    public function storeCutting(Request $request, SlaughterOrder $order, SlaughterService $service)
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'total_input_kg'          => 'required|numeric|min:0.1',
            'session_date'            => 'required|date',
            'products'                => 'required|array|min:1',
            'products.*.type'         => 'required|in:entier,cuisse,aile,poitrine,dos,abats,foie,gesier,autre',
            'products.*.name'         => 'required|string|max:255',
            'products.*.kg'           => 'required|numeric|min:0',
            'products.*.pieces'       => 'nullable|integer|min:0',
            'products.*.price'        => 'nullable|numeric|min:0',
            'products.*.destination'  => 'nullable|in:stock_frais,stock_congele,transformation,vente_directe',
        ]);

        try {
            $session = $service->executeCutting($order, $validated);

            return redirect()->route('slaughter.dashboard')
                ->with('success', "Découpe terminée — Entrée: {$validated['total_input_kg']}kg, Perte: {$session->loss_percent}%.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    // ──────────────────────────────────────────────
    // TRANSFORMATION (fumé, grillé)
    // ──────────────────────────────────────────────

    public function showTransformForm()
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');
        $finishedProducts = FinishedProduct::where('current_quantity_kg', '>', 0)
            ->whereNotIn('product_type', ['fume', 'grille', 'marine'])
            ->get();
        return view('slaughter.transform', compact('finishedProducts'));
    }

    public function storeTransformation(Request $request, SlaughterService $service)
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'product_source'  => 'required|string|max:255',
            'type'            => 'required|in:fume,grille,marine,autre',
            'input_kg'        => 'required|numeric|min:0.1',
            'output_kg'       => 'nullable|numeric|min:0',
            'production_date' => 'required|date',
            'expiry_date'     => 'nullable|date|after:production_date',
            'cost'            => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:500',
        ]);

        $source = FinishedProduct::where('product_name', $validated['product_source'])->first();
        if (! $source || (float) $source->current_quantity_kg < (float) $validated['input_kg']) {
            $dispo = $source ? number_format($source->current_quantity_kg, 1) : '0';
            return back()->withErrors([
                'input_kg' => "Stock insuffisant : {$dispo} kg disponibles pour \"{$validated['product_source']}\" (demandé : {$validated['input_kg']} kg)."
            ])->withInput();
        }

        try {
            $result = $service->executeTransformation($validated);

            return redirect()->route('slaughter.dashboard')
                ->with('success', "Transformation {$result->batch_number} enregistrée — {$result->type_label}.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    // ──────────────────────────────────────────────
    // STOCK PRODUITS FINIS
    // ──────────────────────────────────────────────

    public function finishedProducts()
    {
        if (Gate::denies('abattoir.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $products = FinishedProduct::orderBy('product_type')->get();
        $expiring = FinishedProduct::expiringSoon()->get();
        $lowStock = FinishedProduct::lowStock()->get();

        $kpi = [
            'total_kg'       => $products->sum('current_quantity_kg'),
            'total_pieces'   => $products->sum('current_quantity_pieces'),
            'total_value'    => $products->sum(fn($p) => $p->current_quantity_kg * $p->unit_price),
            'expired_count'  => $products->filter(fn($p) => $p->is_expired)->count(),
            'types_count'    => $products->where('current_quantity_kg', '>', 0)->groupBy('product_type')->count(),
        ];

        return view('slaughter.finished-products', compact('products', 'expiring', 'lowStock', 'kpi'));
    }

    public function updateProduct(Request $request, FinishedProduct $product)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'unit_price'         => 'nullable|numeric|min:0',
            'expiry_date'        => 'nullable|date',
            'alert_threshold_kg' => 'nullable|numeric|min:0',
            'storage_location'   => 'nullable|in:frais,congele,fumoir,vitrine',
        ]);

        $product->update(array_filter($validated, fn($v) => $v !== null));

        return back()->with('success', "\"{$product->product_name}\" mis à jour.");
    }

    public function transferToStock(Request $request, FinishedProduct $product)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'quantity_kg' => 'required|numeric|min:0.1|max:' . $product->current_quantity_kg,
        ]);

        $qty = (float) $validated['quantity_kg'];

        $stockData = [
            'item_name'        => $product->product_name,
            'category'         => 'produits_finis',
        ];

        $farmId = session('current_farm_id');
        if ($farmId && \Illuminate\Support\Facades\Schema::hasColumn('stocks', 'farm_id')) {
            $stockData['farm_id'] = $farmId;
        }

        $stock = \App\Models\Stock::firstOrCreate(
            $stockData,
            [
                // Utilisation de l'unité de poids configurée globalement
                'unit'             => setting('general.weight_unit', 'KG'),
                'current_quantity' => 0,
                'alert_threshold'  => (int) setting('stocks.default_alert_threshold', 0),
                'last_unit_price'  => $product->unit_price ?? 0,
            ]
        );

        $stock->increment('current_quantity', $qty);

        $movementData = [
            'stock_id'     => $stock->id,
            'type'         => 'in',
            'quantity'     => $qty,
            'unit'         => setting('general.weight_unit', 'KG'),
            'notes'        => "Transfert abattoir → magasin ({$product->product_name})",
            'user_id'      => \Illuminate\Support\Facades\Auth::id(),
        ];

        if ($farmId && \Illuminate\Support\Facades\Schema::hasColumn('stock_movements', 'farm_id')) {
            $movementData['farm_id'] = $farmId;
        }

        \App\Models\StockMovement::create($movementData);

        $product->decrement('current_quantity_kg', $qty);

        return back()->with('success', number_format($qty, 1) . " kg de \"{$product->product_name}\" transférés au stock magasin (onglet Produits Finis).");
    }

    public function adjustQuantity(Request $request, FinishedProduct $product)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'new_quantity_kg' => 'required|numeric|min:0',
            'reason'          => 'required|string|max:500',
        ]);

        $oldQty = (float) $product->current_quantity_kg;
        $newQty = (float) $validated['new_quantity_kg'];

        $product->update(['current_quantity_kg' => $newQty]);

        \Illuminate\Support\Facades\Log::info("Ajustement stock produit fini \"{$product->product_name}\" : {$oldQty} → {$newQty} kg — Raison : {$validated['reason']} — Par : " . auth()->user()->name);

        $diff = $newQty - $oldQty;
        $diffLabel = $diff >= 0 ? "+{$diff}" : "{$diff}";

        return back()->with('success', "Ajustement \"{$product->product_name}\" : {$oldQty} → {$newQty} kg ({$diffLabel} kg). Raison : {$validated['reason']}");
    }

    public function dispose(Request $request, FinishedProduct $product)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $qty = (float) $product->current_quantity_kg;

        \Illuminate\Support\Facades\Log::warning("ÉLIMINATION produit fini \"{$product->product_name}\" : {$qty} kg — Raison : {$validated['reason']} — Par : " . auth()->user()->name);

        $product->update(['current_quantity_kg' => 0, 'current_quantity_pieces' => 0]);

        return back()->with('success', "{$qty} kg de \"{$product->product_name}\" éliminés. Raison : {$validated['reason']}");
    }
}