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
 * Point d'entrée unique : agrège trésorerie, charges de fonctionnement et
 * dettes fournisseurs (avec indicateurs de pilotage : Δ mensuel des charges,
 * autonomie de caisse, délai de paiement fournisseurs), et organise les accès
 * par intention (Trésorerie / Dépenses / Achats / Budgets).
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

        $today       = now();
        $monthStart  = $today->copy()->startOfMonth();
        $prevStart   = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $prevEnd     = $today->copy()->subMonthNoOverflow()->endOfMonth();

        // ─── Charges de fonctionnement (registre des dépenses validées) ───
        // « OPEX » du hub : les charges saisies dans le registre des dépenses —
        // l'interface qui alimente les « charges de fonctionnement » du P&L.
        $opexMonth = (float) Expense::validated()->betweenDates($monthStart, $today)->sum('amount');
        $opexPrev  = (float) Expense::validated()->betweenDates($prevStart, $prevEnd)->sum('amount');
        $opexDelta = $opexPrev > 0 ? round((($opexMonth - $opexPrev) / $opexPrev) * 100, 1) : null;

        // Charge mensuelle moyenne sur 3 mois glissants → dénominateur stable
        // pour l'autonomie de caisse (évite qu'un mois creux fausse le ratio).
        $window      = 3;
        $windowStart = $today->copy()->subMonthsNoOverflow($window)->startOfDay();
        $opex3m      = (float) Expense::validated()->betweenDates($windowStart, $today)->sum('amount');
        $opexAvgMonth = $opex3m > 0 ? $opex3m / $window : 0.0;

        // ─── Trésorerie ───
        $treasury      = (float) TreasuryAccount::active()->sum('current_balance');
        $accountsCount = (int) TreasuryAccount::active()->count();

        // Autonomie de caisse : combien de mois la trésorerie couvre-t-elle la
        // charge moyenne ? Indicateur « burn rate » clé pour une ferme. Null si
        // aucune charge de référence (ratio non défini).
        $runwayMonths = $opexAvgMonth > 0 ? round($treasury / $opexAvgMonth, 1) : null;

        // ─── Dettes fournisseurs (comptes à payer) ───
        $countedIds  = SupplierInvoice::counted()->pluck('id');
        $apBilled    = (float) SupplierInvoice::whereIn('id', $countedIds)->sum('total_amount');
        $apPaid      = (float) SupplierPayment::whereIn('supplier_invoice_id', $countedIds)->sum('amount');
        $supplierDebt = round($apBilled - $apPaid, 2);

        // DPO — délai moyen de paiement fournisseurs (jours) : dette rapportée à
        // l'achat quotidien moyen des 90 derniers jours. Null si aucun achat de
        // référence sur la fenêtre.
        $purch90 = (float) SupplierInvoice::whereIn('id', $countedIds)
            ->whereBetween('invoice_date', [$today->copy()->subDays(90)->toDateString(), $today->toDateString()])
            ->sum('total_amount');
        $dpoDays = $purch90 > 0 ? (int) round($supplierDebt / ($purch90 / 90)) : null;

        $kpis = [
            'treasury'       => $treasury,
            'accounts_count' => $accountsCount,
            'runway_months'  => $runwayMonths,
            'opex_month'     => $opexMonth,
            'opex_delta'     => $opexDelta,
            'supplier_debt'  => $supplierDebt,
            'dpo_days'       => $dpoDays,
        ];

        $accounts = TreasuryAccount::active()->orderBy('type')->orderBy('name')->get();

        $recent = TreasuryTransaction::with('account')
            ->latest('transaction_date')->latest('id')
            ->take(6)->get();

        return view('finance.index', compact('kpis', 'accounts', 'recent'));
    }
}
