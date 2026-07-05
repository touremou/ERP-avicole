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

        // Scopes canoniques : physical() exclut le bâtiment virtuel « Zone
        // Fournisseurs Externes », live() exclut les lots virtuels de
        // traçabilité (EXT-, effectif initial nul) — aucun animal réel ne
        // doit manquer NI être compté deux fois dans les KPI du hub.
        $kpis = [
            'buildings'    => (int) Building::physical()->count(),
            'active_lots'  => (int) Batch::active()->live()->count(),
            'livestock'    => (int) Batch::active()->live()->sum('current_quantity'),
            'critical'     => (int) Batch::critical()->live()->count(),
        ];

        $criticalLots = Batch::critical()->live()->with('building')
            ->orderByDesc('qty_dead')->take(6)->get();

        return view('elevage.index', compact('kpis', 'criticalLots'));
    }
}
