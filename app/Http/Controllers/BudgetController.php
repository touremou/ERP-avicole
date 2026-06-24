<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BudgetController — suivi budgétaire par poste de dépense (module: depenses).
 *
 * Récap « dépensé vs budget » par mois : chaque poste (Expense::CATEGORIES)
 * confronte son montant alloué (Budget) à la somme des dépenses VALIDÉES de la
 * période. Permet de saisir/ajuster les budgets et d'exporter le récap (CSV).
 */
class BudgetController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('depenses.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        [$year, $month] = $this->resolvePeriod($request);
        ['rows' => $rows, 'totals' => $totals] = $this->buildRecap($year, $month);

        return view('budgets.index', compact('rows', 'totals', 'year', 'month'));
    }

    /**
     * Enregistre/ajuste les budgets de la période (un montant par poste).
     * Un montant nul ou vide supprime le budget du poste pour la période.
     */
    public function store(Request $request)
    {
        if (Gate::denies('depenses.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $data = $request->validate([
            'year'         => 'required|integer|min:2020|max:2100',
            'month'        => 'required|integer|min:1|max:12',
            'budgets'      => 'array',
            'budgets.*'    => 'nullable|numeric|min:0|max:9999999999',
        ]);

        $count = 0;
        foreach (($data['budgets'] ?? []) as $category => $amount) {
            if (! array_key_exists($category, Expense::CATEGORIES)) {
                continue; // poste inconnu ignoré (anti-injection)
            }

            $amount = (float) $amount;

            if ($amount > 0) {
                Budget::updateOrCreate(
                    ['category' => $category, 'year' => $data['year'], 'month' => $data['month']],
                    ['amount' => $amount, 'created_by' => Auth::id()]
                );
                $count++;
            } else {
                // Montant remis à 0 → on retire le budget du poste pour la période.
                Budget::forPeriod((int) $data['year'], (int) $data['month'])
                    ->where('category', $category)
                    ->delete();
            }
        }

        return redirect()
            ->route('budgets.index', ['year' => $data['year'], 'month' => $data['month']])
            ->with('success', "Budgets enregistrés ({$count} poste(s) alloué(s)).");
    }

    /**
     * Export CSV du récap budgétaire de la période (séparateur « ; » + BOM
     * UTF-8 pour une ouverture directe propre dans Excel).
     */
    public function export(Request $request): StreamedResponse
    {
        if (Gate::denies('depenses.L')) {
            abort(403, 'Accès restreint.');
        }

        [$year, $month] = $this->resolvePeriod($request);
        ['rows' => $rows, 'totals' => $totals] = $this->buildRecap($year, $month);

        $filename = sprintf('budgets-%04d-%02d.csv', $year, $month);

        return response()->streamDownload(function () use ($rows, $totals) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8

            fputcsv($out, ['Poste', 'Budget', 'Dépensé', 'Reste', 'Consommation %', 'Statut'], ';');

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['label'],
                    number_format($r['budget'], 0, ',', ' '),
                    number_format($r['spent'], 0, ',', ' '),
                    number_format($r['remaining'], 0, ',', ' '),
                    $r['budget'] > 0 ? $r['pct'] . ' %' : '—',
                    $r['over'] ? 'DÉPASSEMENT' : ($r['no_budget'] ? 'NON BUDGÉTÉ' : 'OK'),
                ], ';');
            }

            fputcsv($out, [
                'TOTAL',
                number_format($totals['budget'], 0, ',', ' '),
                number_format($totals['spent'], 0, ',', ' '),
                number_format($totals['remaining'], 0, ',', ' '),
                '', '',
            ], ';');

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ──────────────────────────────────────────────
    // INTERNES
    // ──────────────────────────────────────────────

    /** Période (année, mois) demandée, par défaut le mois courant. */
    private function resolvePeriod(Request $request): array
    {
        $year  = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);

        $year  = max(2020, min(2100, $year));
        $month = max(1, min(12, $month));

        return [$year, $month];
    }

    /**
     * Construit le récap budget vs réel d'une période : une ligne par poste
     * (Expense::CATEGORIES), plus les totaux. Source unique partagée par
     * l'affichage et l'export.
     *
     * @return array{rows: \Illuminate\Support\Collection, totals: array}
     */
    private function buildRecap(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to   = (clone $from)->endOfMonth();

        $budgets = Budget::forPeriod($year, $month)->get()->keyBy('category');

        $spentByCategory = Expense::validated()
            ->betweenDates($from->toDateString(), $to->toDateString())
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $rows = collect(Expense::CATEGORIES)->map(function ($label, $key) use ($budgets, $spentByCategory) {
            $budget    = (float) ($budgets[$key]->amount ?? 0);
            $spent     = (float) ($spentByCategory[$key] ?? 0);
            $remaining = $budget - $spent;
            $pct       = $budget > 0 ? round($spent / $budget * 100, 1) : 0.0;

            return [
                'category'  => $key,
                'label'     => $label,
                'budget'    => $budget,
                'spent'     => $spent,
                'remaining' => $remaining,
                'pct'       => $pct,
                'over'      => $budget > 0 && $spent > $budget,
                'no_budget' => $budget <= 0 && $spent > 0,
            ];
        })->values();

        $totals = [
            'budget'    => (float) $rows->sum('budget'),
            'spent'     => (float) $rows->sum('spent'),
            'remaining' => (float) $rows->sum('budget') - (float) $rows->sum('spent'),
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }
}
