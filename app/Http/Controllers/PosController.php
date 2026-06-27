<?php

namespace App\Http\Controllers;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\RecordPayment;
use App\Actions\Sale\ValidateSale;
use App\Models\CashRegisterSession;
use App\Models\Client;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * PosController — Point de vente rapide (caisse), intégré au module Ventes.
 *
 * Une vente POS est une vente COMPLÈTE en un geste : on réutilise la chaîne
 * existante CreateSale → ValidateSale (déstockage) → livraison, payée
 * intégralement. Aucune logique de stock/compta dupliquée : le POS n'est qu'un
 * raccourci d'encaissement par-dessus le parcours de vente standard.
 */
class PosController extends Controller
{
    /** Écran caisse : grille produits (stock vendable) + clients + tarifs par palier. */
    public function index()
    {
        if (Gate::denies('commerce.C')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');
        }

        // Le POS s'appuie sur le CATALOGUE (photo + prix). On affiche TOUS les
        // articles actifs ; la grille distingue :
        //  - article LIÉ à un stock : « Stock : N » (grisé/non vendable si 0) ;
        //  - article NON lié : « Non suivi », vendable librement (qty = null).
        $products = \App\Models\Product::active()
            ->with('stock')
            ->orderBy('name')
            ->get()
            ->map(fn (\App\Models\Product $p) => [
                'id'     => $p->id,                                                  // ID ARTICLE catalogue
                'name'   => $p->name,
                'unit'   => $p->unit,
                'qty'    => $p->stock ? (float) $p->stock->current_quantity : null,  // null = non suivi
                'price'  => (float) (\App\Models\SalePriceList::priceForProduct(null, $p) ?? $p->base_price),
                'photo'  => $p->photo_url,
            ])
            ->values();

        $clients = Client::active()->orderBy('name')->get()
            ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name]);

        // La caisse n'est « ouverte » que si une session l'est : toute vente POS
        // passe par la session (ouverture/clôture + comptage). Sans session, l'écran
        // propose d'ouvrir la caisse plutôt que de vendre.
        $session = $this->openSession();

        return view('pos.index', compact('products', 'clients', 'session'));
    }

    /** Encaissement : crée une vente validée, livrée et soldée en une transaction. */
    public function checkout(Request $request, CreateSale $create, ValidateSale $validate)
    {
        if (Gate::denies('commerce.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        // La vente comptant passe OBLIGATOIREMENT par une session de caisse ouverte
        // (cohérence du Z et du comptage à la clôture).
        if (! $this->openSession()) {
            return redirect()->route('cash-register.index')
                ->with('error', 'Ouvrez d\'abord la caisse (session) avant d\'encaisser.');
        }

        $data = $request->validate([
            'client_id'          => 'nullable|exists:clients,id',
            'payment_method'     => 'required|in:especes,orange_money,virement,cheque',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity'   => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        // Lignes reconstruites côté SERVEUR depuis le CATALOGUE (anti-falsification
        // du type/nom/unité) + contrôle de disponibilité du stock lié.
        $items = [];
        $total = 0.0;
        foreach ($data['items'] as $line) {
            $product = \App\Models\Product::with('stock')->find($line['product_id']);
            if (! $product) {
                continue;
            }

            $qty = (float) $line['quantity'];

            // Article lié au stock : contrôle de disponibilité + déstockage.
            // Article non lié : vente libre, aucun contrôle ni déstockage.
            if ($product->stock) {
                if ($qty > (float) $product->stock->current_quantity) {
                    return back()->with('error', "Stock insuffisant pour {$product->name} (disponible : {$product->stock->current_quantity} {$product->stock->unit}).");
                }
            }

            $price = (float) $line['unit_price'];
            $items[] = [
                'product_type'   => $product->product_type,
                'product_name'   => $product->name,
                'product_id'     => $product->stock_id,  // cible du déstockage (null si non suivi)
                'product_ref_id' => $product->id,        // article catalogue vendu
                'quantity'       => $qty,
                'unit'           => $product->unit,
                'unit_price'     => $price,
            ];
            $total += round($qty * $price, 2);
        }

        if (empty($items)) {
            return back()->with('error', 'Aucun article vendable dans le panier.');
        }

        $clientId = $data['client_id'] ?? $this->walkInClient()->id;

        $sale = DB::transaction(function () use ($create, $validate, $clientId, $items, $total, $data) {
            $sale = $create->execute([
                'client_id'         => $clientId,
                'sale_date'         => now()->toDateString(),
                // Vente au comptoir = encaissement comptant → TICKET de caisse
                // (référence préfixée TKT-), et non un bon de livraison (BL-).
                'type'              => 'comptant',
                'tax_rate'          => 0,
                'delivery_mode'     => 'sur_place',
                'items'             => $items,
                'immediate_payment' => $total, // POS = payé intégralement comptant
                'payment_method'    => $data['payment_method'],
            ]);

            $validate->execute($sale); // déstockage (chaîne standard)
            $sale->update(['status' => 'livre', 'delivered_at' => now()]); // remis en main propre

            return $sale;
        });

        $msg = "Vente encaissée : {$sale->reference} — " . money($total) . '.';

        // Ticket de caisse optionnel (paramètre ventes.ticket_enabled) : sinon on
        // reste sur l'écran POS pour enchaîner la vente suivante.
        return setting('ventes.ticket_enabled', true)
            ? redirect()->route('pos.receipt', $sale)->with('success', $msg)
            : redirect()->route('pos.index')->with('success', $msg);
    }

    /** Ticket de caisse (reçu compact 80 mm), auto-imprimé. */
    public function receipt(Sale $sale)
    {
        if (Gate::denies('commerce.C')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');
        }

        $sale->load(['items', 'client', 'payments']);

        return view('pos.receipt', compact('sale'));
    }

    /**
     * Encaissement EXPRESS du solde d'une vente à crédit (POS appliqué aux
     * ventes existantes) : enregistre le reste dû en un geste puis édite le
     * ticket. Réutilise RecordPayment (mêmes contrôles que le paiement manuel).
     */
    public function encash(Request $request, Sale $sale, RecordPayment $payment)
    {
        if (Gate::denies('commerce.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        // Encaissement express = opération de caisse → session ouverte requise.
        // (Pour un règlement hors caisse, utiliser l'enregistrement de paiement.)
        if (! $this->openSession()) {
            return back()->with('error', 'Ouvrez d\'abord la caisse (session) avant l\'encaissement express.');
        }

        $data = $request->validate([
            'method' => 'required|in:especes,orange_money,virement,cheque',
        ]);

        $remaining = (float) $sale->remaining_amount;

        try {
            $payment->execute($sale, [
                'amount'       => $remaining,
                'method'       => $data['method'],
                'payment_date' => now()->toDateString(),
                'notes'        => 'Encaissement du solde (caisse)',
            ]);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = 'Solde encaissé : ' . money($remaining) . '.';

        return setting('ventes.ticket_enabled', true)
            ? redirect()->route('pos.receipt', $sale)->with('success', $msg)
            : redirect()->route('sales.show', $sale)->with('success', $msg);
    }

    /** Z de caisse : récap des encaissements/remboursements du jour par mode. */
    public function report(Request $request)
    {
        if (Gate::denies('commerce.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');
        }

        $date = $request->input('date');
        try {
            $date = $date ? \Carbon\Carbon::parse($date) : now();
        } catch (\Throwable) {
            $date = now();
        }
        $date = $date->gt(now()) ? now()->toDateString() : $date->toDateString();

        // Remboursements = paiements NÉGATIFS → le net par mode est direct.
        $payments = \App\Models\Payment::whereDate('payment_date', $date)->get();

        $methods = [
            'especes'      => 'Espèces',
            'orange_money' => 'Orange Money / MoMo',
            'virement'     => 'Virement',
            'cheque'       => 'Chèque',
        ];

        $rows = [];
        foreach ($methods as $key => $label) {
            $m = $payments->where('method', $key);
            $in  = (float) $m->where('amount', '>', 0)->sum('amount');
            $out = (float) abs($m->where('amount', '<', 0)->sum('amount'));
            $rows[] = ['label' => $label, 'in' => $in, 'out' => $out, 'net' => $in - $out];
        }

        $totalIn  = array_sum(array_column($rows, 'in'));
        $totalOut = array_sum(array_column($rows, 'out'));

        $report = [
            'rows'          => $rows,
            'total_in'      => $totalIn,
            'total_out'     => $totalOut,
            'total_net'     => $totalIn - $totalOut,
            'tickets_count' => $payments->where('amount', '>', 0)->pluck('sale_id')->unique()->count(),
            'refunds_count' => $payments->where('amount', '<', 0)->count(),
        ];

        return view('pos.report', compact('date', 'report'));
    }

    /** Session de caisse ouverte (une seule par ferme), ou null. */
    private function openSession(): ?CashRegisterSession
    {
        return CashRegisterSession::open()->latest('opened_at')->first();
    }

    /**
     * Création rapide d'un client depuis le POS (JSON) : sans quitter la caisse.
     * Champs minimaux ; le reste est complété plus tard depuis la fiche client.
     */
    public function storeClient(Request $request)
    {
        if (Gate::denies('commerce.C')) {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'phone'    => 'nullable|string|max:30',
            'category' => 'nullable|in:grossiste,detaillant,hotel_restaurant,revendeur,autre',
            'type'     => 'nullable|in:particulier,entreprise',
        ]);

        $lastId = Client::withTrashed()->max('id') ?? 0;

        $client = Client::create([
            'client_id' => sprintf('CLI-%04d', $lastId + 1),
            'name'      => $data['name'],
            'phone'     => $data['phone'] ?? null,
            'category'  => $data['category'] ?? 'detaillant',
            'type'      => $data['type'] ?? 'particulier',
            'status'    => 'actif',
            'credit_limit' => 0,
            'balance'      => 0,
        ]);

        return response()->json(['id' => $client->id, 'name' => $client->name]);
    }

    /** Client « Vente comptoir » par défaut (achat anonyme au comptoir). */
    private function walkInClient(): Client
    {
        return Client::firstOrCreate(
            ['name' => 'Vente comptoir'],
            [
                'client_id' => 'COMPTOIR', 'type' => 'particulier', 'category' => 'detaillant',
                'status' => 'actif', 'credit_limit' => 0, 'balance' => 0,
            ]
        );
    }
}
