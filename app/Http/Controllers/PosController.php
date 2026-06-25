<?php

namespace App\Http\Controllers;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\RecordPayment;
use App\Actions\Sale\ValidateSale;
use App\Models\Client;
use App\Models\Sale;
use App\Models\Stock;
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
    /** Écran caisse : grille produits (stock vendable) + clients. */
    public function index()
    {
        if (Gate::denies('commerce.C')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');
        }

        $products = Stock::where('current_quantity', '>', 0)
            ->orderBy('item_name')
            ->get()
            ->map(function (Stock $s) {
                $type = Stock::CATEGORY_TO_PRODUCT_TYPE[$s->category] ?? null;
                if (! $type) {
                    return null; // catégories non vendables (conso, intrants…) exclues
                }

                return [
                    'id'    => $s->id,
                    'name'  => $s->item_name,
                    'unit'  => $s->unit,
                    'qty'   => (float) $s->current_quantity,
                    'price' => (float) ($s->last_unit_price ?? 0),
                ];
            })
            ->filter()
            ->values();

        $clients = Client::active()->orderBy('name')->get(['id', 'name']);

        return view('pos.index', compact('products', 'clients'));
    }

    /** Encaissement : crée une vente validée, livrée et soldée en une transaction. */
    public function checkout(Request $request, CreateSale $create, ValidateSale $validate)
    {
        if (Gate::denies('commerce.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $data = $request->validate([
            'client_id'          => 'nullable|exists:clients,id',
            'payment_method'     => 'required|in:especes,orange_money,virement,cheque',
            'items'              => 'required|array|min:1',
            'items.*.stock_id'   => 'required|integer|exists:stocks,id',
            'items.*.quantity'   => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        // Lignes construites côté SERVEUR depuis le stock (anti-falsification du
        // type/nom/unité) + contrôle de disponibilité avant déstockage.
        $items = [];
        $total = 0.0;
        foreach ($data['items'] as $line) {
            $stock = Stock::find($line['stock_id']);
            $type = $stock ? (Stock::CATEGORY_TO_PRODUCT_TYPE[$stock->category] ?? null) : null;
            if (! $type) {
                continue;
            }

            $qty = (float) $line['quantity'];
            if ($qty > (float) $stock->current_quantity) {
                return back()->with('error', "Stock insuffisant pour {$stock->item_name} (disponible : {$stock->current_quantity} {$stock->unit}).");
            }

            $price = (float) $line['unit_price'];
            $items[] = [
                'product_type' => $type,
                'product_name' => $stock->item_name,
                'product_id'   => $stock->id,
                'quantity'     => $qty,
                'unit'         => $stock->unit,
                'unit_price'   => $price,
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
                'type'              => 'bon_livraison',
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

        return redirect()->route('pos.receipt', $sale)
            ->with('success', "Vente encaissée : {$sale->reference} — " . money($total) . '.');
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

        return redirect()->route('pos.receipt', $sale)
            ->with('success', 'Solde encaissé : ' . money($remaining) . '.');
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
