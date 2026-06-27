<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Building;
use Illuminate\Support\Facades\Gate;

/**
 * ElevageHubController — HUB du module Élevage.
 *
 * Point d'entrée unifié : effectifs vivants, lots actifs et alertes, + accès
 * groupés (cheptel / santé / référentiel). Même pattern que les hubs Commerce
 * et Finance.
 */
class ElevageHubController extends Controller
{
    public function index()
    {
        if (Gate::denies('elevage.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Élevage.');
        }

        $kpis = [
            'buildings'    => (int) Building::count(),
            'active_lots'  => (int) Batch::active()->count(),
            'livestock'    => (int) Batch::active()->sum('current_quantity'),
            'critical'     => (int) Batch::critical()->count(),
        ];

        $criticalLots = Batch::critical()->with('building')
            ->orderByDesc('qty_dead')->take(6)->get();

        return view('elevage.index', compact('kpis', 'criticalLots'));
    }
}
