<?php

namespace App\Http\Controllers;

use App\Models\TreasuryAccount;
use App\Services\TreasuryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * TreasuryController — comptes de trésorerie (module: depenses).
 *
 * Soldes par canal (Caisse / Mobile Money / Banque), mouvements manuels et
 * transferts. Délègue les écritures à TreasuryService (atomicité + solde).
 */
class TreasuryController extends Controller
{
    public function index()
    {
        if (Gate::denies('depenses.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $accounts = TreasuryAccount::active()->orderBy('name')->get();

        return view('treasury.index', [
            'accounts' => $accounts,
            'total'    => (float) $accounts->sum('current_balance'),
            'types'    => TreasuryAccount::TYPES,
        ]);
    }

    public function show(TreasuryAccount $account)
    {
        if (Gate::denies('depenses.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $transactions = $account->transactions()
            ->with('counterpart')
            ->latest('transaction_date')->latest('id')
            ->paginate(30);

        return view('treasury.show', compact('account', 'transactions'));
    }

    public function storeAccount(Request $request)
    {
        if (Gate::denies('depenses.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'type'            => 'required|in:' . implode(',', array_keys(TreasuryAccount::TYPES)),
            'opening_balance' => 'nullable|numeric|min:0',
        ]);

        $opening = (float) ($data['opening_balance'] ?? 0);
        TreasuryAccount::create([
            'name'            => $data['name'],
            'type'            => $data['type'],
            'opening_balance' => $opening,
            'current_balance' => $opening, // le solde démarre au solde d'ouverture
        ]);

        return back()->with('success', "Compte « {$data['name']} » créé.");
    }

    public function storeMovement(Request $request, TreasuryAccount $account, TreasuryService $service)
    {
        if (Gate::denies('depenses.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $data = $request->validate([
            'direction'   => 'required|in:in,out',
            'amount'      => 'required|numeric|min:0.01',
            'date'        => 'required|date|before_or_equal:today',
            'description' => 'nullable|string|max:255',
        ]);

        if ($data['direction'] === 'out' && (float) $data['amount'] > (float) $account->current_balance) {
            return back()->with('error', "Solde insuffisant sur « {$account->name} ».");
        }

        try {
            $service->record($account, $data['direction'], (float) $data['amount'], [
                'date' => $data['date'], 'description' => $data['description'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Mouvement enregistré.');
    }

    public function transfer(Request $request, TreasuryService $service)
    {
        if (Gate::denies('depenses.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $data = $request->validate([
            'from_id'     => 'required|exists:treasury_accounts,id',
            'to_id'       => 'required|exists:treasury_accounts,id|different:from_id',
            'amount'      => 'required|numeric|min:0.01',
            'date'        => 'required|date|before_or_equal:today',
            'description' => 'nullable|string|max:255',
        ]);

        try {
            $service->transfer(
                TreasuryAccount::findOrFail($data['from_id']),
                TreasuryAccount::findOrFail($data['to_id']),
                (float) $data['amount'],
                ['date' => $data['date'], 'description' => $data['description'] ?? null]
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Transfert effectué.');
    }
}
