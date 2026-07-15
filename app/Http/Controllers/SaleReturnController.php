<?php

namespace App\Http\Controllers;

use App\Actions\Sale\ProcessSaleReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use Carbon\Carbon;
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
    /** Journal des avoirs : liste filtrable des retours sur une période. */
    public function index(Request $request)
    {
        if (Gate::denies('commerce.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Commerce.');
        }

        [$from, $to] = $this->resolvePeriod($request);
        $returns = $this->query($from, $to)->paginate(30)->withQueryString();
        $totalRefund = (float) $this->query($from, $to)->sum('total_refund');

        return view('returns.index', compact('returns', 'from', 'to', 'totalRefund'));
    }

    /** Export CSV du journal des avoirs. */
    public function exportCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (Gate::denies('commerce.L')) {
            abort(403, 'Accès restreint.');
        }

        [$from, $to] = $this->resolvePeriod($request);
        $returns = $this->query($from, $to)->get();

        return response()->streamDownload(function () use ($returns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
            fputcsv($out, ['Avoir', 'Date', 'Vente', 'Client', 'Articles', 'Remboursement', 'Mode', 'Motif'], ';');
            foreach ($returns as $r) {
                fputcsv($out, [
                    $r->reference,
                    $r->return_date->format('d/m/Y'),
                    $r->sale?->reference ?? '',
                    $r->sale?->client?->name ?? '',
                    $r->items_count,
                    number_format((float) $r->total_refund, 0, ',', ' '),
                    $r->refund_method,
                    $r->reason ?? '',
                ], ';');
            }
            fclose($out);
        }, "avoirs-{$from}_{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Export PDF du journal des avoirs. */
    public function exportPdf(Request $request)
    {
        if (Gate::denies('commerce.L')) {
            abort(403, 'Accès restreint.');
        }

        [$from, $to] = $this->resolvePeriod($request);
        $returns = $this->query($from, $to)->get();
        $totalRefund = (float) $returns->sum('total_refund');

        return \Pdf::loadView('returns.pdf.journal', compact('returns', 'from', 'to', 'totalRefund'))
            ->setPaper('a4', 'portrait')
            ->download("avoirs-{$from}_{$to}.pdf");
    }

    /** Requête de base du journal (avoirs d'une période, plus récents d'abord). */
    private function query(string $from, string $to)
    {
        return SaleReturn::with(['sale.client'])
            ->withCount('items')
            ->whereDate('return_date', '>=', $from)
            ->whereDate('return_date', '<=', $to)
            ->latest('return_date')->latest('id');
    }

    /** Période (from, to) demandée, bornée et ordonnée. */
    private function resolvePeriod(Request $request): array
    {
        $parse = function ($v, $default) {
            try {
                return ($v ? Carbon::parse($v) : $default)->toDateString();
            } catch (\Throwable) {
                return $default->toDateString();
            }
        };
        $from = $parse($request->input('from'), now()->startOfMonth());
        $to   = $parse($request->input('to'), now());

        return $from > $to ? [$to, $from] : [$from, $to];
    }

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
