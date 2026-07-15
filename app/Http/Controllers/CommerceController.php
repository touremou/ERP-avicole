<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterSession;
use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\TreasuryAccount;
use Illuminate\Support\Facades\Gate;

/**
 * CommerceController — HUB du module Commerce.
 *
 * Point d'entrée unique : agrège les indicateurs du jour (CA, ventes, créances,
 * trésorerie, état de la caisse) et organise tous les accès par INTENTION
 * (Vendre / Encaisser / Après-vente / Clients), pour transformer un ensemble de
 * menus en un véritable module de vente intégré.
 */
class CommerceController extends Controller
{
    public function index()
    {
        if (Gate::denies('commerce.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');
        }

        $today = Sale::today()->validated();

        $kpis = [
            'ca_jour'      => (float) $today->sum('total_amount'),
            'ventes_jour'  => (int) $today->count(),
            'creances'     => (float) (Sale::unpaid()->validated()->sum('total_amount')
                                       - Sale::unpaid()->validated()->sum('paid_amount')),
            'tresorerie'   => (float) TreasuryAccount::active()->sum('current_balance'),
            'avoirs_jour'  => (float) SaleReturn::whereDate('return_date', today())->sum('total_refund'),
            'clients_dus'  => (int) Client::where('balance', '>', 0)->count(),
        ];

        $session = CashRegisterSession::open()->with('user')->latest('opened_at')->first();

        $recentSales = Sale::with('client')
            ->whereIn('status', ['valide', 'livre'])
            ->latest('sale_date')->latest('id')
            ->take(6)->get();

        return view('commerce.index', [
            'kpis'         => $kpis,
            'session'      => $session,
            'sessionCash'  => $session?->expectedCash(),
            'recentSales'  => $recentSales,
        ]);
    }
}
