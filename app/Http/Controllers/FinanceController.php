<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Illuminate\Support\Facades\Gate;

/**
 * FinanceController — HUB du module Finance (ex-Dépenses).
 *
 * Point d'entrée unique : agrège trésorerie, dépenses du mois et dettes
 * fournisseurs, et organise les accès par intention (Trésorerie / Dépenses /
 * Achats / Budgets). Symétrique du hub Commerce.
 */
class FinanceController extends Controller
{
    public function index()
    {
        // Point d'entrée unifié Finance : accessible à qui a la lecture Dépenses
        // OU Trésorerie. Le cloisonnement reste porté par chaque section (les
        // soldes/mouvements exigent tresorerie.L, les dépenses/dettes depenses.L).
        if (Gate::denies('depenses.L') && Gate::denies('tresorerie.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Finance.');
        }

        $countedIds = SupplierInvoice::counted()->pluck('id');
        $apBilled   = (float) SupplierInvoice::whereIn('id', $countedIds)->sum('total_amount');
        $apPaid     = (float) SupplierPayment::whereIn('supplier_invoice_id', $countedIds)->sum('amount');

        $kpis = [
            'treasury'       => (float) TreasuryAccount::active()->sum('current_balance'),
            'month_expenses' => (float) Expense::validated()
                                    ->whereDate('expense_date', '>=', now()->startOfMonth()->toDateString())
                                    ->whereDate('expense_date', '<=', now()->toDateString())
                                    ->sum('amount'),
            'supplier_debt'  => round($apBilled - $apPaid, 2),
            'accounts_count' => (int) TreasuryAccount::active()->count(),
        ];

        $accounts = TreasuryAccount::active()->orderBy('type')->orderBy('name')->get();

        $recent = TreasuryTransaction::with('account')
            ->latest('transaction_date')->latest('id')
            ->take(6)->get();

        return view('finance.index', compact('kpis', 'accounts', 'recent'));
    }
}
