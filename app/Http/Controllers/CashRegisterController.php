<?php

namespace App\Http\Controllers;

use App\Models\CashRegisterSession;
use App\Models\TreasuryAccount;
use App\Services\TreasuryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * CashRegisterController — sessions de caisse (module: commerce).
 *
 * Ouverture avec fond de caisse, puis clôture avec comptage des billets et
 * calcul de l'écart (réel − théorique). Une seule session ouverte par ferme.
 */
class CashRegisterController extends Controller
{
    public function index()
    {
        if (Gate::denies('commerce.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');
        }

        $open = CashRegisterSession::open()->with('user')->latest('opened_at')->first();
        $history = CashRegisterSession::where('status', 'closed')
            ->with('user')->latest('closed_at')->paginate(15);

        return view('cash-register.index', [
            'open'           => $open,
            'expectedNow'    => $open?->expectedCash(),
            'history'        => $history,
            'denominations'  => CashRegisterSession::DENOMINATIONS,
            'caisseAccounts' => TreasuryAccount::active()->where('type', 'caisse')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function open(Request $request)
    {
        if (Gate::denies('commerce.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        if (CashRegisterSession::open()->exists()) {
            return back()->with('error', 'Une session de caisse est déjà ouverte. Clôturez-la d\'abord.');
        }

        $data = $request->validate([
            'opening_float'       => 'required|numeric|min:0',
            'treasury_account_id' => 'nullable|exists:treasury_accounts,id',
        ]);

        CashRegisterSession::create([
            'user_id'             => Auth::id(),
            // Compte choisi (s'il y a plusieurs caisses), sinon 1re caisse active.
            'treasury_account_id' => $data['treasury_account_id']
                ?? TreasuryAccount::active()->where('type', 'caisse')->value('id'),
            'status'              => 'open',
            'opened_at'           => now(),
            'opening_float'       => (float) $data['opening_float'],
        ]);

        // On ouvre la caisse pour VENDRE → on atterrit sur le POS.
        return redirect()->route('pos.index')->with('success', 'Caisse ouverte. Bonne vente !');
    }

    public function close(Request $request, CashRegisterSession $session, TreasuryService $treasury)
    {
        if (Gate::denies('commerce.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        if (! $session->isOpen()) {
            return back()->with('error', 'Cette session est déjà clôturée.');
        }

        $data = $request->validate([
            'counts'    => 'nullable|array',
            'counts.*'  => 'nullable|integer|min:0',
            'notes'     => 'nullable|string|max:500',
        ]);

        // Comptage des billets → somme réelle (coupures inconnues ignorées).
        $counted = 0.0;
        $denoms = [];
        foreach (($data['counts'] ?? []) as $denom => $qty) {
            $denom = (int) $denom;
            $qty = (int) $qty;
            if (! in_array($denom, CashRegisterSession::DENOMINATIONS, true) || $qty <= 0) {
                continue;
            }
            $denoms[$denom] = $qty;
            $counted += $denom * $qty;
        }

        $expected = $session->expectedCash();

        DB::transaction(function () use ($session, $counted, $expected, $denoms, $data, $treasury) {
            $session->update([
                'status'        => 'closed',
                'closed_at'     => now(),
                'expected_cash' => $expected,
                'counted_cash'  => $counted,
                'difference'    => round($counted - $expected, 2),
                'denominations' => $denoms ?: null,
                'notes'         => $data['notes'] ?? null,
            ]);

            // Report en trésorerie : le compte Caisse suit le COMPTANT physique.
            $this->syncTreasuryToCount($session, $counted, $treasury);
        });

        $ecart = $session->difference;
        $msg = $ecart == 0
            ? 'Caisse clôturée — caisse juste.'
            : 'Caisse clôturée — écart de ' . money(abs($ecart)) . ($ecart > 0 ? ' (excédent).' : ' (manquant).');

        return redirect()->route('cash-register.index')->with($ecart == 0 ? 'success' : 'error', $msg);
    }

    /**
     * Aligne le solde du compte Trésorerie « Caisse » sur le COMPTANT physique
     * de la session (une seule écriture, l'écart éventuel y est absorbé et tracé).
     *
     * On ne poste rien paiement par paiement : le report se fait UNE fois à la
     * clôture → zéro double comptage. Si aucun compte caisse n'est configuré, on
     * n'écrit rien (la session reste un outil de comptage autonome).
     */
    private function syncTreasuryToCount(CashRegisterSession $session, float $counted, TreasuryService $treasury): void
    {
        $account = $session->treasuryAccount
            ?: TreasuryAccount::active()->where('type', 'caisse')->first();

        if (! $account) {
            return;
        }

        $delta = round($counted - (float) $account->current_balance, 2);
        if (abs($delta) < 0.01) {
            return;
        }

        $treasury->record(
            $account,
            $delta > 0 ? 'in' : 'out',
            abs($delta),
            [
                'category'    => 'cloture_caisse',
                'description' => 'Clôture caisse — comptant ' . money($counted)
                                 . ((float) $session->difference != 0.0 ? ' (écart ' . money($session->difference) . ')' : ''),
                'reference'   => 'CAISSE-' . $session->id,
            ]
        );
    }
}
