<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * SupplierInvoiceController — Achats fournisseurs & dettes (module: depenses).
 *
 * Compte à payer symétrique des ventes : achat = débit, règlement = crédit.
 * À la validation, l'achat poste UNE dépense au registre (source unique P&L).
 */
class SupplierInvoiceController extends Controller
{
    /** Journal des achats fournisseurs + synthèse des dettes. */
    public function index(Request $request)
    {
        if (Gate::denies('depenses.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint au module Dépenses.');

        $query = SupplierInvoice::with(['provider', 'payments'])->latest('invoice_date')->latest('id');

        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('provider_id')) $query->where('provider_id', $request->provider_id);
        if ($request->filled('search'))      $query->where('label', 'LIKE', '%' . $request->search . '%');

        $invoices = $query->paginate((int) setting('general.items_per_page', 20))->withQueryString();

        // Aging : on charge les achats comptés (hors annulés) avec leurs règlements
        // pour chiffrer le reste dû et la part ÉCHUE (échéance dépassée, reste > 0).
        $counted = SupplierInvoice::counted()->with('payments')
            ->get(['id', 'total_amount', 'status', 'due_date']);

        $today = now()->startOfDay();
        $overdue = 0.0;
        $overdueCount = 0;
        foreach ($counted as $i) {
            $rem = $i->remaining_amount;
            if ($rem <= 0.01) {
                continue;
            }
            if ($i->due_date && $i->due_date->lt($today)) {
                $overdue += $rem;
                $overdueCount++;
            }
        }

        $stats = [
            'total_billed'  => (float) $counted->sum('total_amount'),
            'total_paid'    => (float) $counted->sum(fn ($i) => $i->paid_amount),
            'total_due'     => round((float) $counted->sum(fn ($i) => $i->remaining_amount), 2),
            'open_count'    => $counted->count(),
            'overdue'       => round($overdue, 2),
            'overdue_count' => $overdueCount,
        ];

        return view('purchases.index', [
            'invoices'  => $invoices,
            'stats'     => $stats,
            'providers' => Provider::active()->orderBy('name')->get(['id', 'name']),
            'filters'   => $request->only(['status', 'provider_id', 'search']),
        ]);
    }

    public function create()
    {
        if (Gate::denies('depenses.C')) return back()->with('error', 'Création d\'achat non autorisée.');

        return view('purchases.create', [
            'providers'  => Provider::active()->orderBy('name')->get(['id', 'name']),
            'categories' => SupplierInvoice::CATEGORIES,
        ]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('depenses.C')) return back()->with('error', 'Création d\'achat non autorisée.');

        $data = $request->validate([
            'provider_id'  => 'required|exists:providers,id',
            'invoice_date' => 'required|date',
            'due_date'     => 'nullable|date|after_or_equal:invoice_date',
            'category'     => 'required|string|max:50',
            'label'        => 'required|string|max:255',
            'total_amount' => 'required|numeric|min:0',
            'notes'        => 'nullable|string|max:500',
        ]);

        $lastId = SupplierInvoice::withoutGlobalScopes()->max('id') ?? 0;

        $invoice = SupplierInvoice::create($data + [
            'reference' => sprintf('ACH-%05d', $lastId + 1),
            'status'    => 'brouillon',
            'user_id'   => Auth::id(),
        ]);

        return redirect()->route('purchases.show', $invoice)
            ->with('success', "Achat {$invoice->reference} enregistré (brouillon). Validez-le pour l'imputer aux dépenses.");
    }

    public function show(SupplierInvoice $invoice)
    {
        if (Gate::denies('depenses.L')) return back()->with('error', 'Accès restreint.');

        $invoice->load(['provider', 'payments.payer', 'expense', 'user']);

        return view('purchases.show', compact('invoice'));
    }

    /** Validation : poste la dépense miroir (coût au P&L, une seule fois). */
    public function validateInvoice(SupplierInvoice $invoice)
    {
        if (Gate::denies('depenses.M')) return back()->with('error', 'Validation non autorisée.');

        if ($invoice->status !== 'brouillon') {
            return back()->with('error', 'Seul un achat en brouillon peut être validé.');
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => 'valide']);
            $invoice->refresh()->syncLedgerExpense(); // dépense « valide » liée
        });

        return back()->with('success', "Achat {$invoice->reference} validé et imputé aux dépenses.");
    }

    /** Annulation : retire l'achat de la dette ET sa dépense du P&L. */
    public function cancel(SupplierInvoice $invoice)
    {
        if (Gate::denies('depenses.M')) return back()->with('error', 'Annulation non autorisée.');

        if ($invoice->status === 'annule') {
            return back()->with('error', 'Cet achat est déjà annulé.');
        }

        DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => 'annule']);
            $invoice->expense?->update(['status' => 'annule']); // sort du P&L
        });

        return back()->with('success', "Achat {$invoice->reference} annulé.");
    }

    /** Règlement fournisseur (crédit). Solde la dette, ne crée aucune dépense. */
    public function pay(Request $request, SupplierInvoice $invoice)
    {
        if (Gate::denies('depenses.C')) return back()->with('error', 'Action non autorisée.');

        if (! $invoice->is_validated) {
            return back()->with('error', 'Validez l\'achat avant de le régler.');
        }

        $data = $request->validate([
            'amount'       => 'required|numeric|not_in:0',
            'method'       => 'required|in:especes,mobile_money,virement,cheque',
            'payment_date' => 'required|date',
            'reference'    => 'nullable|string|max:255',
            'notes'        => 'nullable|string|max:500',
        ]);

        $amount = round((float) $data['amount'], 2);

        // Règlement positif borné au reste dû ; avoir négatif borné au déjà payé.
        if ($amount > 0 && $amount > $invoice->remaining_amount + 0.001) {
            return back()->with('error', 'Le règlement dépasse le reste dû (' . money($invoice->remaining_amount) . ').');
        }
        if ($amount < 0 && abs($amount) > $invoice->paid_amount + 0.001) {
            return back()->with('error', 'L\'avoir dépasse le montant déjà réglé.');
        }

        SupplierPayment::create([
            'supplier_invoice_id' => $invoice->id,
            'amount'              => $amount,
            'payment_date'        => $data['payment_date'],
            'method'              => $data['method'],
            'reference'           => $data['reference'] ?? null,
            'notes'               => $data['notes'] ?? null,
            'paid_by'             => Auth::id(),
        ]);

        return back()->with('success', ($amount >= 0 ? 'Règlement' : 'Avoir') . ' enregistré : ' . money(abs($amount)) . '.');
    }

    /** Relevé de compte fournisseur (achats débit / règlements crédit, solde glissant). */
    public function statement(Provider $provider)
    {
        if (Gate::denies('depenses.L')) return back()->with('error', 'Accès restreint au relevé fournisseur.');

        return view('purchases.statement', [
            'provider'  => $provider,
            'statement' => $this->buildStatement($provider),
        ]);
    }

    public function statementPdf(Provider $provider)
    {
        if (Gate::denies('depenses.L')) return back()->with('error', 'Accès restreint au relevé fournisseur.');

        $pdf = \Pdf::loadView('purchases.pdf.statement', [
            'provider'  => $provider,
            'statement' => $this->buildStatement($provider),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('releve-fournisseur-' . $provider->provider_id . '.pdf');
    }

    /** Timeline + totaux du relevé fournisseur (partagé écran/PDF). */
    private function buildStatement(Provider $provider): array
    {
        $invoices = $provider->supplierInvoices()
            ->where('status', '!=', 'annule')
            ->get(['id', 'reference', 'invoice_date', 'total_amount', 'status']);

        $payments = SupplierPayment::whereIn('supplier_invoice_id', $invoices->pluck('id'))
            ->with('invoice:id,reference')
            ->get();

        // Achat = débit (on doit). Règlement = crédit signé (un avoir est négatif).
        $lines = collect();
        foreach ($invoices as $i) {
            $lines->push([
                'date'   => $i->invoice_date,
                'type'   => 'achat',
                'label'  => 'Achat ' . $i->reference,
                'debit'  => (float) $i->total_amount,
                'credit' => 0.0,
                'seq'    => 0,
            ]);
        }
        foreach ($payments as $p) {
            $isRefund = (float) $p->amount < 0;
            $lines->push([
                'date'   => $p->payment_date,
                'type'   => $isRefund ? 'avoir' : 'reglement',
                'label'  => ($isRefund ? 'Avoir' : 'Règlement') . ' · ' . $p->method_label
                            . ($p->invoice ? ' (' . $p->invoice->reference . ')' : ''),
                'debit'  => 0.0,
                'credit' => (float) $p->amount,
                'seq'    => 1,
            ]);
        }

        $running = 0.0;
        $rows = $lines
            ->sortBy(fn ($l) => $l['date']->format('Y-m-d') . '-' . $l['seq'])
            ->values()
            ->map(function ($l) use (&$running) {
                $running += $l['debit'] - $l['credit'];
                $l['balance'] = round($running, 2);
                return $l;
            });

        $totalDebit  = (float) $invoices->sum('total_amount');
        $totalCredit = (float) $payments->sum('amount');

        return [
            'rows'         => $rows,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'balance'      => round($totalDebit - $totalCredit, 2),
            'count'        => $invoices->count(),
        ];
    }
}
