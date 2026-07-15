<?php

namespace App\Http\Controllers;

use App\Models\RawMaterial;
use App\Models\Formula;
use App\Models\MillProduction;
use App\Models\Stock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Dashboard stratégique de la provenderie.
 *
 * P-05 corrigé : whereMonth + whereYear pour éviter le mélange inter-années.
 * PQ-01 corrigé : suppression du try/catch PDO.
 */
class ProvenderieDashboardController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (Gate::denies('provenderie.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        // 1. Valorisation financière des matières premières (CMUP × stock actif)
        $rawMaterialsValue = RawMaterial::where('is_active', true)
            ->selectRaw('COALESCE(SUM(stock_qty * COALESCE(unit_cost, 0)), 0) as total')
            ->value('total') ?? 0;

        // 2. Alertes de rupture
        $lowStockAlerts = RawMaterial::whereColumn('stock_qty', '<=', 'alert_threshold')
            ->where('is_active', true)
            ->orderBy('stock_qty', 'asc')
            ->get();

        // 3. Dernières productions terminées
        $recentProductions = MillProduction::with(['formula', 'supervisor'])
            ->where('status', 'Terminé')
            ->latest()
            ->take(10)
            ->get();

        // 4. Inventaire aliments finis
        $finishedFeeds = Stock::where('category', Stock::CAT_CONSO)
            ->orderBy('item_name')
            ->get();

        // 5. Volume mensuel (P-05 corrigé : whereMonth ET whereYear)
        $monthlyVolume = MillProduction::where('status', 'Terminé')
            ->whereMonth('finished_at', now()->month)
            ->whereYear('finished_at', now()->year)
            ->sum('quantity_produced');

        return view('provenderie.dashboard', [
            'rawMaterialsValue'  => $rawMaterialsValue,
            'lowStockAlerts'     => $lowStockAlerts,
            'recentProductions'  => $recentProductions,
            'finishedFeeds'      => $finishedFeeds,
            'monthlyVolume'      => $monthlyVolume,
            'totalFormulaCount'  => Formula::where('is_active', true)->count(),
        ]);
    }
}
