<?php

namespace App\Http\Controllers;

use App\Actions\Expense\ApproveExpense;
use App\Actions\Expense\CancelExpense;
use App\Actions\Expense\CreateExpense;
use App\Http\Requests\Expense\StoreExpenseRequest;
use App\Http\Requests\Expense\UpdateExpenseRequest;
use App\Models\Batch;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * ExpenseController — Registre des dépenses (module: depenses).
 *
 * CRUD + workflow de validation (en_attente → valide / annule). Seules les
 * dépenses validées entrent dans les résultats financiers (cf. ReportController
 * et Batch::getNetMarginAttribute).
 */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('depenses.L')) {
            return redirect()->route('dashboard');
        }

        $from = Carbon::parse($request->get('date_from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to   = Carbon::parse($request->get('date_to', now()->toDateString()))->endOfDay();

        $query = Expense::with(['batch:id,code', 'user:id,name'])
            ->betweenDates($from, $to)
            ->byCategory($request->get('category'))
            ->byStatus($request->get('status'));

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(fn ($q) => $q->where('label', 'LIKE', "%{$search}%")
                ->orWhere('reference', 'LIKE', "%{$search}%")
                ->orWhere('supplier_name', 'LIKE', "%{$search}%"));
        }

        $expenses = $query->orderByDesc('expense_date')->orderByDesc('id')
            ->paginate(setting('general.items_per_page', 20))
            ->withQueryString();

        // Statistiques sur la période filtrée.
        $base = Expense::betweenDates($from, $to);
        $stats = [
            'total_valide'   => (float) (clone $base)->where('status', 'valide')->sum('amount'),
            'total_attente'  => (float) (clone $base)->where('status', 'en_attente')->sum('amount'),
            'count_attente'  => (clone $base)->where('status', 'en_attente')->count(),
            'by_category'    => (clone $base)->where('status', 'valide')
                ->selectRaw('category, SUM(amount) as total')
                ->groupBy('category')->pluck('total', 'category'),
        ];

        return view('expenses.index', [
            'expenses'   => $expenses,
            'stats'      => $stats,
            'categories' => Expense::CATEGORIES,
            'from'       => $from,
            'to'         => $to,
        ]);
    }

    public function create()
    {
        if (Gate::denies('depenses.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('expenses.create', [
            'categories'     => Expense::CATEGORIES,
            'paymentMethods' => Expense::PAYMENT_METHODS,
            'batches'        => Batch::active()->live()->orderBy('code')->get(['id', 'code']),
        ]);
    }

    public function store(StoreExpenseRequest $request, CreateExpense $action)
    {
        $expense = $action->execute($request->validated(), $request->file('justificatif'));

        return redirect()->route('expenses.index')
            ->with('success', "Dépense {$expense->reference} enregistrée (en attente de validation).");
    }

    public function show(Expense $expense)
    {
        if (Gate::denies('depenses.L')) {
            return back();
        }

        $expense->load(['batch:id,code', 'user:id,name', 'approver:id,name']);

        return view('expenses.show', compact('expense'));
    }

    public function edit(Expense $expense)
    {
        if (Gate::denies('depenses.M')) {
            return back();
        }

        if ($expense->status !== 'en_attente') {
            return back()->with('error', 'Seule une dépense en attente peut être modifiée.');
        }

        return view('expenses.edit', [
            'expense'        => $expense,
            'categories'     => Expense::CATEGORIES,
            'paymentMethods' => Expense::PAYMENT_METHODS,
            'batches'        => Batch::active()->live()->orderBy('code')->get(['id', 'code']),
        ]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        if ($expense->status !== 'en_attente') {
            return back()->with('error', 'Seule une dépense en attente peut être modifiée.');
        }

        $data = $request->validated();

        // Remplacement du justificatif : on purge l'ancien fichier pour ne pas
        // laisser d'orphelins sur le disque (même garde-fou que UpdateEmployee).
        if ($request->hasFile('justificatif')) {
            if ($expense->justificatif_path && Storage::disk('public')->exists($expense->justificatif_path)) {
                Storage::disk('public')->delete($expense->justificatif_path);
            }
            $data['justificatif_path'] = $request->file('justificatif')->store('expenses/justificatifs', 'public');
        }

        $expense->update($data);

        return redirect()->route('expenses.show', $expense)->with('success', 'Dépense mise à jour.');
    }

    /**
     * Télécharge le justificatif d'une dépense (facture, reçu, note de frais).
     */
    public function downloadJustificatif(Expense $expense)
    {
        if (Gate::denies('depenses.L')) {
            return back()->with('error', 'Action non autorisée.');
        }

        if (! $expense->justificatif_path || ! Storage::disk('public')->exists($expense->justificatif_path)) {
            return back()->with('error', 'Aucun justificatif disponible pour cette dépense.');
        }

        $extension = pathinfo($expense->justificatif_path, PATHINFO_EXTENSION);

        return Storage::disk('public')->download(
            $expense->justificatif_path,
            "justificatif-{$expense->reference}.{$extension}"
        );
    }

    public function approve(Expense $expense, ApproveExpense $action)
    {
        if (Gate::denies('depenses.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        try {
            $action->execute($expense);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Dépense {$expense->reference} validée et intégrée aux résultats.");
    }

    public function cancel(Request $request, Expense $expense, CancelExpense $action)
    {
        if (Gate::denies('depenses.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $action->execute($expense, $request->input('reason'));

        return back()->with('success', "Dépense {$expense->reference} annulée.");
    }

    public function destroy(Expense $expense)
    {
        if (Gate::denies('depenses.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $expense->delete();

        return redirect()->route('expenses.index')->with('success', 'Dépense supprimée.');
    }
}
