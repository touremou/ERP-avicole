<?php

namespace App\Http\Controllers;

use App\Actions\Sale\ProcessSaleReturn;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * SaleReturnController — retours client & remboursements (module: commerce).
 *
 * Délègue le traitement (restock + réduction vente + remboursement) à l'action
 * ProcessSaleReturn pour ne pas dupliquer la logique stock/compta.
 */
class SaleReturnController extends Controller
{
    /** Formulaire de retour : choisir les quantités rendues par ligne. */
    public function create(Sale $sale)
    {
        if (Gate::denies('commerce.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        if (! in_array($sale->status, ['valide', 'livre'], true)) {
            return back()->with('error', 'Seule une vente validée ou livrée peut faire l\'objet d\'un retour.');
        }

        $sale->load(['items', 'client']);

        return view('returns.create', compact('sale'));
    }

    /** Traite le retour. */
    public function store(Request $request, Sale $sale, ProcessSaleReturn $action)
    {
        if (Gate::denies('commerce.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $data = $request->validate([
            'reason'        => 'nullable|string|max:500',
            'refund_method' => 'required|in:especes,orange_money,virement,cheque',
            'returns'       => 'required|array',
            'returns.*'     => 'nullable|numeric|min:0',
        ]);

        // Ne garder que les quantités strictement positives.
        $lines = collect($data['returns'])
            ->map(fn ($q) => (float) $q)
            ->filter(fn ($q) => $q > 0)
            ->all();

        if (empty($lines)) {
            return back()->with('error', 'Aucune quantité à retourner.');
        }

        try {
            $return = $action->execute($sale, $lines, $data['reason'] ?? '', $data['refund_method']);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $sale)
            ->with('success', "Retour {$return->reference} traité — remboursement : " . money($return->total_refund) . '.');
    }
}
