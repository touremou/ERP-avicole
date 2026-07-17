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
        if (Gate::denies('tresorerie.L')) {
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
        if (Gate::denies('tresorerie.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $transactions = $account->transactions()
            ->with(['counterpart', 'source'])
            ->latest('transaction_date')->latest('id')
            ->paginate(30);

        return view('treasury.show', compact('account', 'transactions'));
    }

    public function storeAccount(Request $request)
    {
        if (Gate::denies('tresorerie.C')) {
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

    /** Modification d'un compte (libellé, type, activation, notes). Le solde
     *  n'est PAS modifié ici — il découle des mouvements. */
    public function updateAccount(Request $request, TreasuryAccount $account)
    {
        if (Gate::denies('tresorerie.M')) {
            return back()->with('error', 'Modification non autorisée.');
        }

        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'type'      => 'required|in:' . implode(',', array_keys(TreasuryAccount::TYPES)),
            'is_active' => 'nullable|boolean',
            'notes'     => 'nullable|string|max:1000',
        ]);

        $account->update([
            'name'      => $data['name'],
            'type'      => $data['type'],
            'is_active' => (bool) ($data['is_active'] ?? $account->is_active),
            'notes'     => $data['notes'] ?? $account->notes,
        ]);

        return back()->with('success', "Compte « {$account->name} » mis à jour.");
    }

    /** Suppression d'un compte. Refusée s'il porte des mouvements (intégrité de
     *  l'historique) : on invite alors à le DÉSACTIVER. */
    public function destroyAccount(TreasuryAccount $account)
    {
        if (Gate::denies('tresorerie.S')) {
            return back()->with('error', 'Suppression réservée au responsable trésorerie.');
        }

        if ($account->transactions()->exists()) {
            return back()->with('error', "Compte « {$account->name} » non supprimable : il porte des mouvements. Désactivez-le plutôt pour préserver l'historique.");
        }

        $name = $account->name;
        $account->delete();

        return redirect()->route('treasury.index')->with('success', "Compte « {$name} » supprimé.");
    }

    /** État des flux de trésorerie : entrées/sorties par catégorie sur une période. */
    public function report(Request $request)
    {
        if (Gate::denies('tresorerie.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        return view('treasury.report', $this->buildReport($request));
    }

    /** Export PDF de l'état des flux (même agrégation que l'écran). */
    public function reportPdf(Request $request)
    {
        if (Gate::denies('tresorerie.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $data = $this->buildReport($request);
        $pdf = \Pdf::loadView('treasury.report-pdf', $data)->setPaper('a4', 'portrait');

        return $pdf->download('flux-tresorerie-' . $data['from']->toDateString() . '-' . $data['to']->toDateString() . '.pdf');
    }

    /** Agrégation partagée (écran / PDF) des flux de la période. */
    private function buildReport(Request $request): array
    {
        $from = $this->parseDate($request->input('from'), now()->startOfMonth());
        $to   = $this->parseDate($request->input('to'), now()->endOfMonth());
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $accountId = $request->input('account_id') ?: null;
        $accounts  = TreasuryAccount::active()->orderBy('name')->get();

        $base = \App\Models\TreasuryTransaction::whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);
        if ($accountId) {
            $base->where('treasury_account_id', $accountId);
        }

        // Agrégat par catégorie (entrée / sortie).
        $rows = (clone $base)
            ->selectRaw('category, direction, SUM(amount) as total')
            ->groupBy('category', 'direction')->get();

        $byCategory = [];
        foreach ($rows as $r) {
            $byCategory[$r->category] ??= ['in' => 0.0, 'out' => 0.0];
            $byCategory[$r->category][$r->direction] = (float) $r->total;
        }
        uasort($byCategory, fn ($a, $b) => ($b['in'] + $b['out']) <=> ($a['in'] + $a['out']));

        $totalIn  = (float) (clone $base)->where('direction', 'in')->sum('amount');
        $totalOut = (float) (clone $base)->where('direction', 'out')->sum('amount');

        // Synthèse par compte (flux de la période).
        $perAccount = $accounts->map(function ($acc) use ($from, $to) {
            $q = \App\Models\TreasuryTransaction::where('treasury_account_id', $acc->id)
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);
            return [
                'account' => $acc,
                'in'      => (float) (clone $q)->where('direction', 'in')->sum('amount'),
                'out'     => (float) (clone $q)->where('direction', 'out')->sum('amount'),
            ];
        });

        return compact('from', 'to', 'accountId', 'accounts', 'byCategory', 'totalIn', 'totalOut', 'perAccount');
    }

    /** Export CSV des mouvements de trésorerie de la période (filtre compte optionnel). */
    public function reportCsv(Request $request)
    {
        if (Gate::denies('tresorerie.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $from = $this->parseDate($request->input('from'), now()->startOfMonth());
        $to   = $this->parseDate($request->input('to'), now()->endOfMonth());
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $accountId = $request->input('account_id') ?: null;

        $txs = \App\Models\TreasuryTransaction::with('account')
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->when($accountId, fn ($q) => $q->where('treasury_account_id', $accountId))
            ->orderBy('transaction_date')->orderBy('id')->get();

        return response()->streamDownload(function () use ($txs) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
            fputcsv($out, ['Date', 'Compte', 'Sens', 'Montant', 'Catégorie', 'Description', 'Référence'], ';');
            foreach ($txs as $t) {
                fputcsv($out, [
                    $t->transaction_date->format('d/m/Y'),
                    $t->account?->name,
                    $t->direction === 'in' ? 'Entrée' : 'Sortie',
                    number_format((float) $t->amount, 2, '.', ''),
                    $t->category,
                    $t->description,
                    $t->reference,
                ], ';');
            }
            fclose($out);
        }, 'flux-tresorerie-' . $from->toDateString() . '-' . $to->toDateString() . '.csv');
    }

    private function parseDate(?string $value, \Carbon\Carbon $default): \Carbon\Carbon
    {
        try {
            return $value ? \Carbon\Carbon::parse($value) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /** Mapping mode de paiement → compte par défaut (espèces→Caisse, OM→Mobile…). */
    public function updateMapping(Request $request)
    {
        if (Gate::denies('tresorerie.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $channels = ['especes', 'mobile_money', 'virement', 'cheque'];
        $data = $request->validate([
            'mapping'   => 'array',
            'mapping.*' => 'nullable|exists:treasury_accounts,id',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($channels, $data) {
            foreach ($channels as $channel) {
                $accountId = $data['mapping'][$channel] ?? null;

                // On purge l'ancien défaut de ce canal, puis on (ré)assigne.
                TreasuryAccount::where('default_for_method', $channel)->update(['default_for_method' => null]);
                if ($accountId) {
                    TreasuryAccount::whereKey($accountId)->update(['default_for_method' => $channel]);
                }
            }
        });

        return back()->with('success', 'Affectation des comptes mise à jour.');
    }

    public function storeMovement(Request $request, TreasuryAccount $account, TreasuryService $service)
    {
        if (Gate::denies('tresorerie.C')) {
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
        if (Gate::denies('tresorerie.C')) {
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
