<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Provider;
use App\Http\Requests\Stock\StoreStockRequest;
use App\Http\Requests\Stock\MoveStockRequest;
use App\Http\Requests\Stock\UpdateStockRequest;
use App\Actions\Stock\CreateStockAction;
use App\Actions\Stock\UpdateStockAction;
use App\Actions\Stock\MoveStockAction;
use App\Actions\Stock\DeleteStockAction;
use App\Actions\Stock\SyncEggStocksAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Exception;

class StockController extends Controller
{
    public function index(Request $request)
    {

        if (Gate::denies('logistique.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $category = $request->get('category', Stock::CAT_OEUFS);
        $stocks = Stock::where('category', $category)->get();
        
        $recentMovements = StockMovement::with(['stock', 'user'])
            ->whereHas('stock', fn($q) => $q->where('category', $category))
            ->latest()
            ->paginate((int) setting('general.items_per_page', 20));

        return view('stocks.index', compact('stocks', 'recentMovements', 'category'));
    }
    
    public function create(Request $request)
    {
        if (Gate::denies('logistique.C')) return back()->with('error', 'Action non autorisée.'); 
        
        $category = $request->get('category', Stock::CAT_OEUFS);
        $providers = Provider::orderBy('name')->get(); 
        
        return view('stocks.create', compact('category', 'providers'));
    }
   
    // INJECTION DE L'ACTION CREATE
    public function store(StoreStockRequest $request, CreateStockAction $action)
    {
        $stock = $action->execute($request->validated(), Auth::id());

        return redirect()->route('stocks.index', ['category' => $stock->category])
                         ->with('success', "Article créé : {$stock->item_name}.");
    }

    // INJECTION DE L'ACTION MOVE
    public function move(MoveStockRequest $request, MoveStockAction $action)
    {
        $action->execute(
            $request->stock_id, 
            $request->type, 
            (float) $request->quantity, 
            $request->notes, 
            Auth::id()
        );

        return back()->with('success', 'Mouvement enregistré.');
    }
    
    public function edit($id)
    {
        if (Gate::denies('logistique.M')) return back()->with('error', 'Modification non autorisée.');
        
        $stock = Stock::findOrFail($id);
        $providers = Provider::orderBy('name')->get(); 
        
        return view('stocks.edit', compact('stock', 'providers'));
    }

    // INJECTION DE L'ACTION UPDATE
    public function update(UpdateStockRequest $request, $id, UpdateStockAction $action)
    {
        $stock = Stock::findOrFail($id);
        
        $action->execute($stock, $request->validated(), Auth::id());

        return redirect()->route('stocks.index', ['category' => $stock->category])
                         ->with('success', "Mise à jour réussie.");
    }

    public function show($id)
    {
        $stock = Stock::findOrFail($id);
        $movements = $stock->movements()->with('user')->latest()->get();

        $stats = [
            'total_in' => $stock->movements()->where('type', 'in')->where('created_at', '>=', now()->subDays(30))->sum('quantity'),
            'total_out' => $stock->movements()->where('type', 'out')->where('created_at', '>=', now()->subDays(30))->sum('quantity'),
        ];

        return view('stocks.show', compact('stock', 'movements', 'stats'));
    }

    // INJECTION DE L'ACTION DELETE
    public function destroy($id, DeleteStockAction $action)
    {
        if (Gate::denies('logistique.S')) return back()->with('error', 'Action réservée aux administrateurs.');
        
        $stock = Stock::findOrFail($id);
        $category = $stock->category;
        
        try {
            $action->execute($stock);
            return redirect()->route('stocks.index', ['category' => $category])
                             ->with('success', 'Article supprimé avec succès.');
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // INJECTION DE L'ACTION SYNC
    public function syncAll(SyncEggStocksAction $action)
    {
        if (Gate::denies('logistique.M')) return back()->with('error', 'Action non autorisée.');

        $action->execute();

        return back()->with('success', '🚀 Synchronisation des stocks terminée.');
    }
}