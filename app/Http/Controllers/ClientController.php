<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('commerce.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');

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
        if (Gate::denies('commerce.C')) return back()->with('error', 'Création de client non autorisée.');
        return view('clients.create');
    }

    public function store(StoreClientRequest $request)
    {
        // La vérification Gate est gérée dans la FormRequest
        $validated = $request->validated();

        $lastId = Client::withTrashed()->max('id') ?? 0;
        $validated['client_id'] = sprintf('CLI-%04d', $lastId + 1);

        Client::create($validated);

        return redirect()->route('clients.index')
            ->with('success', "Client {$validated['name']} enregistré.");
    }

    public function show(Client $client)
    {
        if (Gate::denies('commerce.L')) return back()->with('error', 'Accès restreint à la fiche client.');

        $client->load(['sales' => fn($q) => $q->latest('sale_date')->take(20), 'sales.payments']);

        $stats = [
            'total_purchases' => $client->sales()->whereNotIn('status', ['annule'])->sum('total_amount'),
            'total_paid'      => $client->sales()->whereNotIn('status', ['annule'])->sum('paid_amount'),
            'sales_count'     => $client->sales()->whereNotIn('status', ['annule'])->count(),
        ];

        return view('clients.show', compact('client', 'stats'));
    }

    public function edit(Client $client)
    {
        if (Gate::denies('commerce.M')) return back()->with('error', 'Modification de client non autorisée.');
        return view('clients.edit', compact('client'));
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        // La validation et le Gate sont gérés dans UpdateClientRequest
        $client->update($request->validated());

        return redirect()->route('clients.show', $client)
            ->with('success', 'Fiche client mise à jour.');
    }

    public function destroy(Client $client)
    {
        if (Gate::denies('commerce.S')) return back()->with('error', 'Suppression de client réservée aux responsables.');

        if ($client->balance > 0) {
            return back()->with('error', "Impossible de supprimer : le client a un solde dû de " . number_format($client->balance) . " GNF.");
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