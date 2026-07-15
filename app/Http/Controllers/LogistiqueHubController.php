<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Models\StockAdjustment;
use Illuminate\Support\Facades\Gate;

/**
 * LogistiqueHubController — HUB du module Logistique.
 *
 * Valeur du stock, alertes de seuil et démarque du mois, + accès groupés
 * (magasin / expéditions). Même pattern que les autres hubs.
 */
class LogistiqueHubController extends Controller
{
    public function index()
    {
        if (Gate::denies('logistique.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Logistique.');
        }

        $lowQuery = Stock::whereColumn('current_quantity', '<=', 'alert_threshold')->where('alert_threshold', '>', 0);

        $kpis = [
            'references'  => (int) Stock::count(),
            'stock_value' => (float) Stock::selectRaw('COALESCE(SUM(current_quantity * last_unit_price), 0) as v')->value('v'),
            'low'         => (int) (clone $lowQuery)->count(),
            'shrinkage'   => (float) StockAdjustment::losses()
                                ->betweenDates(now()->startOfMonth()->toDateString(), now()->toDateString())
                                ->sum('value_impact'),
        ];

        $lowStocks = (clone $lowQuery)->orderBy('current_quantity')->take(6)->get();

        return view('logistique.index', compact('kpis', 'lowStocks'));
    }
}
