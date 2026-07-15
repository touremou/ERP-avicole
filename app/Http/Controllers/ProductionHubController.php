<?php

namespace App\Http\Controllers;

use App\Models\EggProduction;
use App\Models\Incubation;
use App\Models\MilkProduction;
use Illuminate\Support\Facades\Gate;

/**
 * ProductionHubController — HUB du module Production (œufs, lait, couvoir).
 *
 * Route 'productions.*' (pluriel) — distincte de 'production.*' (Provenderie).
 */
class ProductionHubController extends Controller
{
    public function index()
    {
        if (Gate::denies('production.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Production.');
        }

        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $kpis = [
            'eggs_today'  => (int) EggProduction::whereDate('production_date', $today)->sum('total_eggs_collected'),
            'eggs_month'  => (int) EggProduction::whereDate('production_date', '>=', $monthStart)
                                    ->whereDate('production_date', '<=', $today)->sum('total_eggs_collected'),
            'milk_today'  => (float) MilkProduction::whereDate('production_date', $today)->sum('total_liters'),
            // Incubations EN COURS = non closes. Le statut terminal est « clos »
            // (« termine » n'existe pas → l'ancien filtre comptait TOUT, closes incluses).
            'incub_open'  => (int) Incubation::where('status', '!=', 'clos')->count(),
        ];

        $recentEggs = EggProduction::with('batch')
            ->latest('production_date')->latest('id')->take(6)->get();

        return view('productions.index', compact('kpis', 'recentEggs'));
    }
}
