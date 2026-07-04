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

        // Transformations engagées sans pesée de sortie (fumage/grillage en
        // cours) : à terminer depuis le dashboard dès la sortie du fumoir.
        $ongoingTransformations = Transformation::where('status', 'en_cours')
            ->latest('production_date')
            ->get();

        return view('slaughter.dashboard', compact('kpi', 'pendingOrders', 'recentResults', 'finishedProducts', 'expiring', 'ongoingTransformations'));
    }

    // ──────────────────────────────────────────────
    // ORDRES D'ABATTAGE
    // ──────────────────────────────────────────────

    public function createOrder()
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        // Tous les lots actifs sont éligibles à l'abattage/la transformation,
        // quelle que soit l'espèce (volaille, ruminants, porcins, lapins...).
        // Les lots en quarantaine restent listés mais VERROUILLÉS (le refus
        // serveur est dans storeOrder + executeSlaughter).
        $batches = Batch::active()
            ->where('current_quantity', '>', 0)
            ->with(['building', 'productionType'])
            ->withExists(['healthIncidents as is_under_quarantine' => fn ($q) => $q
                ->where('is_quarantined', true)
                ->where('status', '!=', \App\Models\HealthIncident::STATUS_RESOLVED)])
            ->get()
            ->sortBy(fn (Batch $batch) => $batch->type . $batch->code)
            ->values();

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

        // Biosécurité : pas d'ordre d'abattage sur un lot en quarantaine
        // (délai d'attente médicamenteux — la garde est re-jouée sous verrou
        // à l'exécution, une quarantaine pouvant être posée entre-temps).
        if ($quarantine = $batch->activeQuarantine()) {
            return back()->withErrors([
                'batch_id' => "Le lot {$batch->code} est en QUARANTAINE sanitaire (incident n°{$quarantine->id}) — "
                    . "abattage interdit jusqu'à la levée par le circuit santé."
            ])->withInput();
        }

        SlaughterOrder::create(array_merge($validated, [
            'order_number' => SlaughterOrder::generateNumber(), // La gestion du préfixe se fera dans le Model
            'requested_by' => Auth::id(),
        ]));

        return redirect()->route('slaughter.dashboard')
            ->with('success', "Ordre d'abattage créé — {$validated['planned_quantity']} sujets du lot {$batch->code}.");
    }

    /**
     * Annule un ordre encore planifié (erreur de saisie, changement de plan).
     * Un ordre exécuté ne s'annule pas — l'abattage est irréversible.
     */
    public function cancelOrder(SlaughterOrder $order)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($order) {
                $order = SlaughterOrder::lockForUpdate()->findOrFail($order->id);

                if ($order->status !== 'planifie') {
                    throw new \Exception("Seul un ordre planifié peut être annulé (statut : {$order->status}).");
                }

                $order->update([
                    'status' => 'annule',
                    'notes'  => trim(($order->notes ? $order->notes . ' | ' : '')
                        . '[ANNULÉ par ' . (Auth::user()?->name ?? 'Système') . ' le ' . now()->format('d/m/Y H:i') . ']'),
                ]);
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Ordre {$order->order_number} annulé.");
    }

    // ──────────────────────────────────────────────
    // EXÉCUTION ABATTAGE
    // ──────────────────────────────────────────────

    public function showExecuteForm(SlaughterOrder $order)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');
        $order->load('batch.building', 'batch.species');

        // Bandes de rendement carcasse propres à l'espèce du lot (volaille,
        // ovin, bovin, porcin, lapin, poisson — cf. config/butchery.php).
        $yield = \App\Services\ButcheryNomenclature::carcassYieldForSpecies($order->batch?->species);

        return view('slaughter.execute', compact('order', 'yield'));
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

        // ── Cohérence des pesées ──
        // Une carcasse ne peut jamais peser plus que l'animal vivant, quelle
        // que soit l'espèce (rendement carcasse toujours < 100%). Sans ce
        // garde-fou, une erreur de saisie (ex : poids vif en kg/sujet au lieu
        // du total) provoque un rendement aberrant (> 999,99%) qui dépasse la
        // capacité de la colonne `carcass_yield_percent` et fait échouer
        // l'enregistrement avec une erreur SQL brute illisible pour l'utilisateur.
        if ((float) $validated['total_carcass_weight_kg'] > (float) $validated['total_live_weight_kg']) {
            return back()->withErrors([
                'total_carcass_weight_kg' => "Alerte Système : le poids carcasse ({$validated['total_carcass_weight_kg']} kg) ne peut pas dépasser le poids vif ({$validated['total_live_weight_kg']} kg). Vérifiez les deux pesées.",
            ])->withInput();
        }

        if ((int) ($validated['condemned_count'] ?? 0) > (int) $validated['actual_quantity']) {
            return back()->withErrors([
                'condemned_count' => "Le nombre de saisies sanitaires ({$validated['condemned_count']}) ne peut pas dépasser le nombre de sujets abattus ({$validated['actual_quantity']}).",
            ])->withInput();
        }

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

        // La découpe consomme la carcasse « entier frais » mise en stock par
        // l'abattage. Tant que l'ordre n'est pas terminé, aucune carcasse n'existe
        // → on bloque (sinon la découpe créerait des morceaux « fantômes »).
        if ($order->status !== 'termine') {
            return redirect()->route('slaughter.dashboard')
                ->with('error', "L'abattage de l'ordre {$order->order_number} doit être terminé avant la découpe.");
        }

        $order->load(['result', 'cuttingSessions.products', 'batch.species']);

        // Morceaux de découpe adaptés à l'espèce du lot abattu.
        $cuts = \App\Services\ButcheryNomenclature::cutsForSpecies($order->batch?->species);

        return view('slaughter.cutting', compact('order', 'cuts'));
    }

    public function storeCutting(Request $request, SlaughterOrder $order, SlaughterService $service)
    {
        if (Gate::denies('abattoir.C')) return back()->with('error', 'Action non autorisée.');

        // Enforcement serveur : pas de découpe tant que l'abattage n'est pas
        // terminé (carcasse en stock). removeFromFinishedStock() est silencieux
        // sur un stock absent — sans cette garde, on créerait des morceaux sans
        // jamais consommer de carcasse.
        if ($order->status !== 'termine') {
            return back()->with('error', "L'abattage doit être terminé avant d'enregistrer une découpe.")->withInput();
        }

        $order->loadMissing('batch.species');
        $allowedTypes = \App\Services\ButcheryNomenclature::cutCodesForSpecies($order->batch?->species);

        $validated = $request->validate([
            'total_input_kg'          => 'required|numeric|min:0.1',
            'session_date'            => 'required|date',
            'products'                => 'required|array|min:1',
            'products.*.type'         => 'required|in:' . implode(',', $allowedTypes),
            'products.*.name'         => 'required|string|max:255',
            'products.*.kg'           => 'required|numeric|min:0',
            'products.*.pieces'       => 'nullable|integer|min:0',
            'products.*.price'        => 'nullable|numeric|min:0',
            'products.*.destination'  => 'nullable|in:stock_frais,stock_congele,transformation,vente_directe',
        ]);

        // Garde-fou cohérence : la somme des morceaux ne peut dépasser l'entrée
        // (une découpe génère des pertes, jamais un gain de matière).
        $totalOutput = collect($validated['products'])->sum(fn ($p) => (float) ($p['kg'] ?? 0));
        if ($totalOutput > (float) $validated['total_input_kg'] + 0.001) {
            return back()->withErrors([
                'total_input_kg' => "Alerte Système : le total des morceaux (" . number_format($totalOutput, 1) . " kg) dépasse le poids de carcasses entré (" . number_format((float) $validated['total_input_kg'], 1) . " kg). Une découpe ne peut pas produire plus de matière qu'elle n'en reçoit.",
            ])->withInput();
        }

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

    /**
     * Termine une transformation « en cours » : pesée de sortie connue
     * (fumage/grillage terminé) → rendement + entrée en stock produits finis.
     */
    public function completeTransformation(Request $request, Transformation $transformation, SlaughterService $service)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'output_kg' => 'required|numeric|min:0.1',
        ]);

        try {
            $result = $service->completeTransformation($transformation, (float) $validated['output_kg']);

            return back()->with('success',
                "Transformation {$result->batch_number} terminée — rendement {$result->yield_percent} %, "
                . number_format((float) $result->output_kg, 1) . " kg entrés en stock."
            );
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

        // Journal des dernières corrections/destructions (traçabilité).
        $recentAdjustments = \App\Models\FinishedProductAdjustment::with(['finishedProduct', 'user'])
            ->latest()
            ->take(10)
            ->get();

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
            'quantity_kg' => 'required|numeric|min:0.1',
        ]);

        $qty = (float) $validated['quantity_kg'];

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($product, $qty) {
                // Disponibilité contrôlée SOUS verrou (la règle max:… de la
                // validation lisait un stock non verrouillé — course possible
                // entre deux transferts, motif C1).
                $product = FinishedProduct::lockForUpdate()->findOrFail($product->id);
                if ($qty > (float) $product->current_quantity_kg) {
                    throw new \Exception(
                        "Stock insuffisant : " . number_format((float) $product->current_quantity_kg, 1)
                        . " kg disponibles pour \"{$product->product_name}\" (demandé : " . number_format($qty, 1) . " kg)."
                    );
                }

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
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', number_format($qty, 1) . " kg de \"{$product->product_name}\" transférés au stock magasin (onglet Produits Finis).");
    }

    public function adjustQuantity(Request $request, FinishedProduct $product)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'new_quantity_kg' => 'required|numeric|min:0',
            'reason'          => 'required|string|max:500',
        ]);

        // Écriture atomique + JOURNAL EN BASE (le log fichier seul n'était
        // pas requêtable — même exigence de traçabilité que la démarque).
        $oldQty = 0.0;
        $newQty = (float) $validated['new_quantity_kg'];

        \Illuminate\Support\Facades\DB::transaction(function () use ($product, $newQty, $validated, &$oldQty) {
            $product = FinishedProduct::lockForUpdate()->findOrFail($product->id);
            $oldQty  = (float) $product->current_quantity_kg;

            $product->update(['current_quantity_kg' => $newQty]);

            \App\Models\FinishedProductAdjustment::create([
                'finished_product_id' => $product->id,
                'user_id'             => Auth::id(),
                'type'                => \App\Models\FinishedProductAdjustment::TYPE_ADJUSTMENT,
                'old_kg'              => $oldQty,
                'new_kg'              => $newQty,
                'reason'              => $validated['reason'],
            ]);
        });

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

        // Écriture atomique + JOURNAL EN BASE (traçabilité des destructions —
        // péremption, saisie sanitaire... — exigence sanitaire ET comptable).
        $qty = 0.0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($product, $validated, &$qty) {
            $product = FinishedProduct::lockForUpdate()->findOrFail($product->id);
            $qty     = (float) $product->current_quantity_kg;

            $product->update(['current_quantity_kg' => 0, 'current_quantity_pieces' => 0]);

            \App\Models\FinishedProductAdjustment::create([
                'finished_product_id' => $product->id,
                'user_id'             => Auth::id(),
                'type'                => \App\Models\FinishedProductAdjustment::TYPE_DISPOSAL,
                'old_kg'              => $qty,
                'new_kg'              => 0,
                'reason'              => $validated['reason'],
            ]);
        });

        \Illuminate\Support\Facades\Log::warning("ÉLIMINATION produit fini \"{$product->product_name}\" : {$qty} kg — Raison : {$validated['reason']} — Par : " . auth()->user()->name);

        return back()->with('success', "{$qty} kg de \"{$product->product_name}\" éliminés. Raison : {$validated['reason']}");
    }
}