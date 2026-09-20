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
        if (Gate::denies('caisse.L')) {
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
            /*
             * LE SOLDE VOYAGE AVEC LE COMPTE, et c'est le point du correctif.
             *
             * On ne chargeait que ['id', 'name'] : l'écran demandait au caissier
             * le fond de caisse dans un champ codé en dur à zéro, sans jamais
             * lui montrer le nombre que le système tient pourtant à jour — et
             * s'en sert, dans la même requête, pour l'autre moitié du verdict.
             */
            'caisseAccounts' => TreasuryAccount::active()->where('type', 'caisse')
                ->orderBy('name')->get(['id', 'name', 'current_balance']),
        ]);
    }

    public function open(Request $request)
    {
        if (Gate::denies('caisse.C')) {
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
        if (Gate::denies('caisse.C')) {
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

        /*
         * LE DÉSACCORD ENTRE LES DEUX VERDICTS, RENDU VISIBLE.
         *
         * La clôture produit deux écarts sur le même tiroir, dans la même
         * requête, à partir de deux bases différentes :
         *
         *   • la SESSION      : difference = compté − expectedCash()
         *                       (expectedCash part du fond TAPÉ à l'ouverture)
         *   • la TRÉSORERIE   : delta      = compté − current_balance
         *
         * Par soustraction, ils divergent d'exactement :
         *
         *     delta − difference  =  expectedCash() − solde du compte
         *                         =  fond tapé − solde à l'ouverture
         *
         * Ce décalage était absorbé en silence par l'écriture de clôture. La
         * session pouvait annoncer un EXCÉDENT là où le grand-livre voyait un
         * MANQUANT, sans que rien ne relève la contradiction — et c'est ce
         * nombre-là, celui de la session, que porte l'alerte anti-détournement.
         *
         * L'écran propose désormais le solde à l'ouverture : accepté, les deux
         * verdicts coïncident par construction. S'il est écarté — un tiroir
         * peut réellement différer, et c'est l'événement à saisir — le décalage
         * est NOMMÉ, ici et sur l'écriture.
         */
        $compteCaisse = $session->treasuryAccount
            ?: TreasuryAccount::active()->where('type', 'caisse')->first();

        $ecartOuverture = $compteCaisse
            ? round($expected - (float) $compteCaisse->current_balance, 2)
            : 0.0;

        DB::transaction(function () use ($session, $counted, $expected, $denoms, $data, $treasury, $ecartOuverture) {
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
            $this->syncTreasuryToCount($session, $counted, $treasury, $ecartOuverture);
        });

        /*
         * ALERTE ANTI-FRAUDE — l'écart ne restait annoncé qu'ICI, à l'écran, pour
         * celui-là même qui venait de clôturer la caisse. Le promoteur, qui vit à
         * l'étranger, n'en savait rien : le signal le plus direct de détournement
         * n'atteignait personne.
         *
         * Jamais bloquante : une alerte qui échoue ne doit pas empêcher une caisse
         * de se clôturer — sans quoi le comptage physique serait perdu.
         */
        try {
            app(\App\Services\NotificationHub::class)->alertCashDiscrepancy($session->fresh());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Alerte d'écart de caisse non émise : {$e->getMessage()}");
        }

        $ecart = $session->difference;
        $msg = $ecart == 0
            ? 'Caisse clôturée — caisse juste.'
            : 'Caisse clôturée — écart de ' . money(abs($ecart)) . ($ecart > 0 ? ' (excédent).' : ' (manquant).');

        // Le fond saisi s'écartait du grand-livre : on le dit, sans quoi l'écart
        // ci-dessus se lit comme un écart de la journée alors qu'il vient d'avant.
        if (abs($ecartOuverture) >= 0.01) {
            $msg .= $ecartOuverture > 0
                ? ' Écart d\'ouverture : le fond saisi dépassait le grand-livre de ' . money($ecartOuverture) . '.'
                : ' Écart d\'ouverture : le fond saisi était inférieur au grand-livre de ' . money(abs($ecartOuverture)) . '.';
        }

        /*
         * Le VERDICT reste celui de la session — c'est son écart à elle qui dit
         * si la caisse est juste. Le décalage d'ouverture s'ajoute au texte sans
         * teinter le message en rouge : à la toute première session, le fond
         * physique entre légitimement au grand-livre, et transformer ce moment
         * en alerte apprendrait à ignorer les vraies.
         */
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
    private function syncTreasuryToCount(
        CashRegisterSession $session,
        float $counted,
        TreasuryService $treasury,
        float $ecartOuverture = 0.0
    ): void {
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
                                 . ((float) $session->difference != 0.0 ? ' (écart ' . money($session->difference) . ')' : '')
                                 // Sans cette mention, l'écriture absorbait le décalage
                                 // d'ouverture sans laisser de quoi le retrouver.
                                 . (abs($ecartOuverture) >= 0.01 ? ' — dont écart d\'ouverture ' . money($ecartOuverture) : ''),
                'reference'   => 'CAISSE-' . $session->id,
            ]
        );
    }
}
