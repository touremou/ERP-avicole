<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Payment;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('clients.read')) return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');

        $query = Client::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('search')) {
            $query->where('name', 'LIKE', '%' . $request->search . '%');
        }
        if ($request->boolean('with_debt')) {
            $query->where('balance', '>', 0);
        }

        $clients = $query->withCount('sales')->orderBy('name')->paginate((int) setting('general.items_per_page', 20));

        $stats = [
            'total_clients'   => Client::count(),
            'total_debt'      => Client::where('balance', '>', 0)->sum('balance'),
            'over_limit_count' => Client::overCreditLimit()->count(),
        ];

        return view('clients.index', compact('clients', 'stats'));
    }

    public function create()
    {
        if (Gate::denies('clients.create')) return back()->with('error', 'Création de client non autorisée.');
        return view('clients.create', ['priceLists' => \App\Models\SalePriceList::orderBy('name')->get()]);
    }

    public function store(StoreClientRequest $request)
    {
        // La vérification Gate est gérée dans la FormRequest
        $validated = $request->validated();

        // Le plafond de crédit est une donnée COMMERCIALE : un créateur venu de
        // l'Annuaire (sans commerce.C) ne peut pas le fixer, même en forgeant la
        // requête → on l'ignore (défaut 0 = pas de plafond).
        if (Gate::denies('commerce.C')) {
            unset($validated['credit_limit']);
        }

        $lastId = Client::withTrashed()->max('id') ?? 0;
        $validated['client_id'] = sprintf('CLI-%04d', $lastId + 1);

        Client::create($validated);

        return redirect()->route('clients.index')
            ->with('success', "Client {$validated['name']} enregistré.");
    }

    public function show(Client $client)
    {
        if (Gate::denies('clients.read')) return back()->with('error', 'Accès restreint à la fiche client.');

        $client->load(['sales' => fn($q) => $q->latest('sale_date')->take(20), 'sales.payments']);

        $stats = [
            'total_purchases' => $client->sales()->whereNotIn('status', ['annule'])->sum('total_amount'),
            'total_paid'      => $client->sales()->whereNotIn('status', ['annule'])->sum('paid_amount'),
            'sales_count'     => $client->sales()->whereNotIn('status', ['annule'])->count(),
        ];

        return view('clients.show', compact('client', 'stats'));
    }

    /**
     * Relevé de compte client : timeline chronologique des ventes (débit) et des
     * règlements (crédit signé — un remboursement est un crédit négatif), avec
     * solde glissant. Le solde final = recalculateBalance() (la créance du client).
     */
    public function statement(Client $client)
    {
        if (Gate::denies('commerce.L')) return back()->with('error', 'Accès restreint au relevé client.');

        return view('clients.statement', [
            'client'    => $client,
            'statement' => $this->buildStatement($client),
        ]);
    }

    public function statementPdf(Client $client)
    {
        if (Gate::denies('commerce.L')) return back()->with('error', 'Accès restreint au relevé client.');

        $pdf = \Pdf::loadView('clients.pdf.statement', [
            'client'    => $client,
            'statement' => $this->buildStatement($client),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('releve-' . $client->client_id . '.pdf');
    }

    /** Construit la timeline + les totaux du relevé (partagé écran/PDF). */
    private function buildStatement(Client $client): array
    {
        // Ventes comptées (hors brouillon/annulé) — même périmètre que recalculateBalance().
        $sales = $client->sales()
            ->whereNotIn('status', ['annule', 'brouillon'])
            ->get(['id', 'reference', 'sale_date', 'total_amount', 'status', 'payment_status']);

        $payments = Payment::whereIn('sale_id', $sales->pluck('id'))
            ->with('sale:id,reference')
            ->get();

        // Vente = débit (le client doit). Règlement = crédit signé : un remboursement
        // (avoir) est un crédit négatif qui ré-augmente le solde dû.
        $lines = collect();
        foreach ($sales as $s) {
            $lines->push([
                'date'   => $s->sale_date,
                'type'   => 'vente',
                'label'  => 'Vente ' . $s->reference,
                'debit'  => (float) $s->total_amount,
                'credit' => 0.0,
                'seq'    => 0, // la vente précède ses règlements à date égale
            ]);
        }
        foreach ($payments as $p) {
            $isRefund = (float) $p->amount < 0;
            $lines->push([
                'date'   => $p->payment_date,
                'type'   => $isRefund ? 'remboursement' : 'reglement',
                'label'  => ($isRefund ? 'Remboursement' : 'Règlement') . ' · ' . $p->method_label
                            . ($p->sale ? ' (' . $p->sale->reference . ')' : ''),
                'debit'  => 0.0,
                'credit' => (float) $p->amount, // signé
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

        $totalDebit  = (float) $sales->sum('total_amount');
        $totalCredit = (float) $payments->sum('amount'); // net (remboursements déduits)

        return [
            'rows'         => $rows,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'balance'      => round($totalDebit - $totalCredit, 2),
            'sales_count'  => $sales->count(),
        ];
    }

    public function edit(Client $client)
    {
        if (Gate::denies('clients.modify')) return back()->with('error', 'Modification de client non autorisée.');
        return view('clients.edit', ['client' => $client, 'priceLists' => \App\Models\SalePriceList::orderBy('name')->get()]);
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        // La validation et le Gate sont gérés dans UpdateClientRequest
        $validated = $request->validated();

        // Crédit = donnée commerciale : un éditeur venu de l'Annuaire (sans
        // commerce.M) ne peut pas le modifier, même en forgeant la requête.
        if (Gate::denies('commerce.M')) {
            unset($validated['credit_limit']);
        }

        $client->update($validated);

        return redirect()->route('clients.show', $client)
            ->with('success', 'Fiche client mise à jour.');
    }

    public function destroy(Client $client)
    {
        if (Gate::denies('commerce.S')) return back()->with('error', 'Suppression de client réservée aux responsables.');

        if ($client->balance > 0) {
            return back()->with('error', "Impossible de supprimer : le client a un solde dû de " . money($client->balance) . ".");
        }

        if ($client->sales()->whereNotIn('status', ['annule'])->exists()) {
            return back()->with('error', 'Impossible de supprimer un client avec un historique de ventes. Changez son statut en "suspendu" ou "blacklisté".');
        }

        $client->delete();
        return redirect()->route('clients.index')->with('success', 'Client supprimé.');
    }

    /**
     * Référentiel clients pour la saisie hors-ligne (IndexedDB).
     * Colonnes limitées : suffisant pour peupler le sélecteur de la vente rapide.
     */
    public function getOfflineClients(): \Illuminate\Http\JsonResponse
    {
        if (Gate::denies('commerce.L')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(
            Client::active()
                ->orderBy('name')
                ->get(['id', 'name', 'phone', 'credit_limit', 'balance'])
        );
    }
}