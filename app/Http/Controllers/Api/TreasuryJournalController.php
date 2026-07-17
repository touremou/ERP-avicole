<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use App\Support\JournalPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Journal de trésorerie du jour (terrain) — consultation mobile.
 *
 * Mouvements du jour (entrées/sorties, toutes sources : caisse POS, saisie
 * web, transferts) + récap (encaissé / décaissé / net) et soldes courants par
 * compte. Bornée à la ferme par FarmScope, lecture tresorerie.L (+ verrou
 * licence via Gate::before). Remplacement complet côté client (comme /tasks).
 */
class TreasuryJournalController extends Controller
{
    public function today(Request $request): JsonResponse
    {
        if (Gate::denies('tresorerie.L')) {
            abort(403, 'Lecture de la Trésorerie non autorisée.');
        }

        $period = JournalPeriod::resolve($request);

        $movements = TreasuryTransaction::query()
            ->with('account:id,name')
            ->whereBetween('transaction_date', [$period['start'], $period['end']])
            ->orderByDesc('created_at')
            ->get(['id', 'treasury_account_id', 'direction', 'amount', 'category', 'description', 'created_at']);

        $in = (float) $movements->where('direction', 'in')->sum('amount');
        $out = (float) $movements->where('direction', 'out')->sum('amount');

        $accounts = TreasuryAccount::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'current_balance', 'is_active']);

        return response()->json([
            'movements' => $movements->map(fn (TreasuryTransaction $tx) => [
                'id'          => $tx->id,
                'account'     => $tx->account?->name,
                'direction'   => $tx->direction,
                'amount'      => (float) $tx->amount,
                'category'    => $tx->category,
                'description' => $tx->description,
                'created_at'  => $tx->created_at?->toIso8601String(),
            ])->values(),
            'summary' => [
                'in'  => $in,
                'out' => $out,
                'net' => $in - $out,
            ],
            'accounts' => $accounts->map(fn (TreasuryAccount $account) => [
                'id'        => $account->id,
                'name'      => $account->name,
                'type'      => $account->type,
                'balance'   => (float) $account->current_balance,
                'is_active' => (bool) $account->is_active,
            ])->values(),
            'total_balance' => (float) $accounts->where('is_active', true)->sum('current_balance'),
            'period'        => ['key' => $period['key'], 'label' => $period['label']],
            'server_time'   => now()->toIso8601String(),
        ]);
    }
}
