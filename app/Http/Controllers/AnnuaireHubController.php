<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Provider;
use Illuminate\Support\Facades\Gate;

/**
 * AnnuaireHubController — HUB du module Annuaire / TIERS.
 *
 * Cloisonnement (moindre privilège) : ce hub ne concerne QUE les tiers
 * externes (fournisseurs / partenaires). La RH interne (employés, paie,
 * pointage, congés) vit dans le module `rh` (RhHubController) — un accès
 * Annuaire n'ouvre donc plus les données du personnel ni les salaires.
 */
class AnnuaireHubController extends Controller
{
    public function index()
    {
        if (Gate::denies('annuaire.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Annuaire.');
        }

        $kpis = [
            'providers'        => (int) Provider::count(),
            'providers_active' => (int) Provider::active()->count(),
            // Clients = tiers partagés (visibles via annuaire OU commerce).
            'clients'          => Gate::allows('clients.read') ? (int) Client::count() : 0,
        ];

        return view('annuaire.index', compact('kpis'));
    }
}
