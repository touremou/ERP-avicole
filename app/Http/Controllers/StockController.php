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
use App\Actions\Stock\CompareEggStockToLedger;
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
    
    /**
     * Export CSV de l'inventaire d'une catégorie.
     *
     * Le bouton « Export » existait sur l'écran des stocks depuis toujours ; la
     * route aussi. La MÉTHODE, non : cliquer déclenchait une erreur serveur. Un
     * bouton visible qui échoue est pire qu'un bouton absent — on le reclique.
     *
     * Format aligné sur les autres exports de la maison (démarque, dépenses) :
     * point-virgule et BOM UTF-8, pour qu'Excel ouvre le fichier correctement
     * sans manipulation.
     */
    public function export(Request $request, $category)
    {
        if (Gate::denies('logistique.L')) return back()->with('error', 'Accès restreint.');

        $stocks = Stock::where('category', $category)->orderBy('item_name')->get();

        $filename = 'stocks-' . $category . '-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($stocks) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // BOM UTF-8 : accents lisibles dans Excel
            \App\Support\CsvExport::putRow($out, [
                'Article', 'Catégorie', 'Unité', 'Quantité', 'Seuil d\'alerte',
                'Prix unitaire', 'Valeur', 'Lot', 'Péremption', 'Sous le seuil',
            ], ';');

            foreach ($stocks as $s) {
                \App\Support\CsvExport::putRow($out, [
                    $s->item_name,
                    $s->category,
                    $s->unit,
                    $s->current_quantity,
                    $s->alert_threshold,
                    $s->unit_price,
                    round((float) $s->current_quantity * (float) $s->unit_price),
                    $s->lot_number,
                    $s->expiry_date?->format('d/m/Y'),
                    // Colonne explicite : c'est l'information qu'on vient chercher
                    // dans un export d'inventaire, et la calculer soi-même dans
                    // le tableur invite à l'erreur.
                    ($s->alert_threshold > 0 && $s->current_quantity <= $s->alert_threshold) ? 'OUI' : '',
                ], ';');
            }

            fclose($out);
        }, $filename);
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

    /**
     * ÉTAT DES STOCKS D'ŒUFS FACE À LEUR REGISTRE. N'écrit plus rien.
     *
     * Cette route d'administration écrasait le niveau de chaque calibre avec la
     * somme de TOUTE la production enregistrée — la même colonne que la vente
     * décrémente. Elle remettait donc en stock ce qui était déjà vendu : 100
     * alvéoles produites, 30 vendues, 70 restantes ; après appel, 100.
     *
     * Aucun écran ne l'appelle aujourd'hui (vérifié : `stocks.syncAll` n'apparaît
     * dans aucune vue), elle n'est donc joignable qu'en POST direct — mais la tâche
     * de nuit `stocks:sync` portait le MÊME calcul, et celle-là tournait
     * réellement, chaque nuit. C'est par là que l'écart se reconstituait.
     *
     * On ne recalcule plus : `current_quantity` est tenue à chaque mouvement,
     * c'est elle la source de vérité. On CONSTATE l'écart et on renvoie vers le
     * bon outil — l'inventaire physique, qui écrit un ajustement en règle.
     * Voir CompareEggStockToLedger pour le détail, notamment pourquoi le registre
     * ne permet pas de trancher seul.
     */
    public function syncAll(CompareEggStockToLedger $action)
    {
        if (Gate::denies('logistique.M')) return back()->with('error', 'Action non autorisée.');

        $ecarts = collect($action->execute(session('current_farm_id')))
            ->filter(fn ($l) => abs($l['gap']) >= 0.001);

        if ($ecarts->isEmpty()) {
            return back()->with('success', __('Chaque calibre s’accorde avec son registre de mouvements.'));
        }

        return back()->with('error', __('Écart(s) constaté(s) : :liste. Rien n’a été modifié — passer par l’inventaire physique (Production d’œufs) pour corriger.', [
            'liste' => $ecarts->map(fn ($l) => sprintf('%s %+.2f %s', $l['item'], $l['gap'], $l['unit']))->implode(' ; '),
        ]));
    }
}