<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Recouvrement : encours clients en retard de paiement + relances (CRM léger).
 */
class ReceivablesController extends Controller
{
    public function index()
    {
        if (Gate::denies('commerce.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');
        }

        $overdue = Sale::overdue()
            ->with(['client', 'reminders' => fn ($q) => $q->latest('sent_at')])
            ->validated()
            ->orderBy('sale_date')
            ->get();

        $totalDue = $overdue->sum(fn ($s) => $s->remaining_amount);

        return view('sales.receivables', compact('overdue', 'totalDue'));
    }

    public function remind(Sale $sale, NotificationHub $hub)
    {
        if (Gate::denies('commerce.M')) {
            return back()->with('error', 'Relance non autorisée.');
        }

        if ($sale->remaining_amount <= 0) {
            return back()->with('error', 'Cette vente est déjà soldée.');
        }

        $sent = $hub->remindClientPayment($sale, Auth::id());

        return back()->with(
            $sent ? 'success' : 'warning',
            $sent
                ? "Relance envoyée à {$sale->client?->name} pour {$sale->reference}."
                : "Relance journalisée, mais le client n'a pas de téléphone joignable."
        );
    }
}
