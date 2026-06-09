<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Client;
use App\Models\Stock;
use App\Models\Batch;
use App\Models\PriceList;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Actions\Sale\CreateSale;
use App\Actions\Sale\ValidateSale;
use App\Actions\Sale\CancelSale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        // 🔒 CORRECTION : commerce.L
        if (Gate::denies('commerce.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');

        $query = Sale::with(['client', 'user']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('sale_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('sale_date', '<=', $request->date_to);
        }

        $sales = $query->latest('sale_date')->paginate(20);

        // Stats du jour
        $todaySales = Sale::today()->validated();
        $stats = [
            'today_count'   => $todaySales->count(),
            'today_total'   => $todaySales->sum('total_amount'),
            'today_cash'    => $todaySales->where('payment_status', 'solde')->sum('total_amount'),
            'unpaid_total'  => Sale::unpaid()->validated()->sum('total_amount') - Sale::unpaid()->validated()->sum('paid_amount'),
        ];

        $clients = Client::active()->orderBy('name')->get(['id', 'name']);

        return view('sales.index', compact('sales', 'stats', 'clients'));
    }

    public function create(Request $request)
    {
        // 🔒 CORRECTION : commerce.C
        if (Gate::denies('commerce.C')) return back()->with('error', 'Création de vente non autorisée.');

        $clients = Client::active()->orderBy('name')->get();
        
        // 💡 AJOUT : Exclusion des bâtiments virtuels (stockage)
        $batches = Batch::where('status', 'Actif')
                        ->whereHas('building', fn($q) => $q->physical())
                        ->with('building')
                        ->get();
                        
        $stocks  = Stock::where('current_quantity', '>', 0)->get();
        $prices  = PriceList::where('is_active', true)->get();

        // Pré-sélection client si passé en query string
        $selectedClient = $request->filled('client_id')
            ? Client::find($request->client_id)
            : null;

        return view('sales.create', compact('clients', 'batches', 'stocks', 'prices', 'selectedClient'));
    }

    public function store(StoreSaleRequest $request, CreateSale $action)
    {
        // 🔒 AJOUT SÉCURITÉ : La vérification manquait
        if (Gate::denies('commerce.C')) return back()->with('error', 'Création de vente non autorisée.');

        $sale = $action->execute($request->validated());

        return redirect()->route('sales.show', $sale)
            ->with('success', "Vente {$sale->reference} créée — Total : " . number_format($sale->total_amount) . " GNF.");
    }

    public function show(Sale $sale)
    {
        // 🔒 CORRECTION : commerce.L
        if (Gate::denies('commerce.L')) return back()->with('error', 'Accès restreint aux détails de la vente.');

        $sale->load(['client', 'items', 'payments.receiver', 'user']);

        return view('sales.show', compact('sale'));
    }

    public function validate(Sale $sale, ValidateSale $action) // Note: "validate" est un mot réservé en PHP, attention si vous rencontrez des bugs, préférez "approve" ou "validateSale"
    {
        // 🔒 CORRECTION : commerce.M
        if (Gate::denies('commerce.M')) return back()->with('error', 'Validation de la vente non autorisée.');

        try {
            $action->execute($sale);
            return back()->with('success', "Vente {$sale->reference} validée. Stocks mis à jour.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deliver(Sale $sale)
    {
        // 🔒 CORRECTION : commerce.M
        if (Gate::denies('commerce.M')) return back()->with('error', 'Action de livraison non autorisée.');

        if ($sale->status !== 'valide') {
            return back()->with('error', 'La vente doit être validée avant la livraison.');
        }

        $sale->update([
            'status'       => 'livre',
            'delivered_at' => now(),
        ]);

        return back()->with('success', "Livraison enregistrée pour {$sale->reference}.");
    }

    public function cancel(Sale $sale, CancelSale $action)
    {
        // 🔒 CORRECTION : commerce.S
        if (Gate::denies('commerce.S')) return back()->with('error', 'Annulation de vente réservée aux responsables.');

        try {
            $action->execute($sale, request('reason', 'Annulation manuelle'));
            return redirect()->route('sales.index')
                ->with('success', "Vente {$sale->reference} annulée. Stocks restaurés.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function print(Sale $sale)
    {
        // 🔒 CORRECTION : commerce.L
        if (Gate::denies('commerce.L')) return back()->with('error', 'Accès restreint.');

        $sale->load(['client', 'items', 'payments']);

        return view('sales.print', compact('sale'));
    }
}