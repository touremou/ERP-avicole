<?php

namespace App\Http\Controllers;

use App\Actions\Stock\CreateStockAdjustment;
use App\Models\Stock;
use App\Models\StockAdjustment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * StockAdjustmentController — démarque & ajustements d'inventaire (logistique).
 *
 * Journal valorisé des écarts de stock (pertes/gains), avec motif. Source de
 * pilotage anti-démarque ; n'écrit pas au P&L (cf. CreateStockAdjustment).
 */
class StockAdjustmentController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('logistique.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        [$from, $to] = $this->period($request);

        $query = StockAdjustment::with(['stock', 'user'])->betweenDates($from, $to);

        if ($request->filled('stock_id')) $query->where('stock_id', $request->stock_id);
        if ($request->filled('reason'))   $query->where('reason', $request->reason);
        if ($request->filled('type'))     $query->where('type', $request->type);

        $adjustments = (clone $query)->latest('adjustment_date')->latest('id')
            ->paginate((int) setting('general.items_per_page', 20))->withQueryString();

        // KPIs valorisés sur le périmètre filtré.
        $base = StockAdjustment::betweenDates($from, $to)
            ->when($request->filled('stock_id'), fn ($q) => $q->where('stock_id', $request->stock_id))
            ->when($request->filled('reason'), fn ($q) => $q->where('reason', $request->reason));

        $lossValue = (float) (clone $base)->where('type', 'perte')->sum('value_impact');
        $gainValue = (float) (clone $base)->where('type', 'gain')->sum('value_impact');

        return view('stock-adjustments.index', [
            'adjustments' => $adjustments,
            'stats'       => [
                'loss_value' => $lossValue,
                'gain_value' => $gainValue,
                'net_value'  => round($gainValue - $lossValue, 2),
                'count'      => (clone $base)->count(),
            ],
            'stocks'  => Stock::orderBy('item_name')->get(['id', 'item_name']),
            'reasons' => StockAdjustment::REASONS,
            'filters' => $request->only(['stock_id', 'reason', 'type', 'from', 'to']),
            'from'    => $from,
            'to'      => $to,
        ]);
    }

    public function create(Request $request)
    {
        if (Gate::denies('logistique.C')) return back()->with('error', 'Action non autorisée.');

        return view('stock-adjustments.create', [
            'stocks'    => Stock::orderBy('item_name')->get(['id', 'item_name', 'unit', 'current_quantity', 'last_unit_price']),
            'reasons'   => StockAdjustment::REASONS,
            'stock_id'  => $request->get('stock_id'),
        ]);
    }

    public function store(Request $request, CreateStockAdjustment $action)
    {
        if (Gate::denies('logistique.C')) return back()->with('error', 'Action non autorisée.');

        $data = $request->validate([
            'stock_id'         => 'required|exists:stocks,id',
            'counted_quantity' => 'required|numeric|min:0',
            'reason'           => 'required|in:' . implode(',', array_keys(StockAdjustment::REASONS)),
            'adjustment_date'  => 'required|date|before_or_equal:today',
            'notes'            => 'nullable|string|max:500',
        ]);

        $adjustment = $action->execute(
            (int) $data['stock_id'],
            (float) $data['counted_quantity'],
            $data['reason'],
            $data['notes'] ?? null,
            Auth::id(),
            $data['adjustment_date'],
        );

        return redirect()->route('stock-adjustments.index')->with(
            'success',
            "Ajustement {$adjustment->reference} : {$adjustment->reason_label} ("
            . ($adjustment->is_loss ? '−' : '+') . money($adjustment->value_impact) . ')'
        );
    }

    public function exportCsv(Request $request)
    {
        if (Gate::denies('logistique.L')) return back()->with('error', 'Accès restreint.');

        [$from, $to] = $this->period($request);
        $rows = StockAdjustment::with('stock')->betweenDates($from, $to)->latest('adjustment_date')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
            fputcsv($out, ['Référence', 'Date', 'Article', 'Motif', 'Type', 'Avant', 'Après', 'Écart', 'CMP', 'Valeur'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->reference, $r->adjustment_date->format('d/m/Y'), $r->stock?->item_name,
                    $r->reason_label, $r->type, $r->quantity_before, $r->quantity_after,
                    $r->delta, $r->unit_cost, $r->value_impact,
                ], ';');
            }
            fclose($out);
        }, 'demarque-' . $from . '-' . $to . '.csv');
    }

    public function exportPdf(Request $request)
    {
        if (Gate::denies('logistique.L')) return back()->with('error', 'Accès restreint.');

        [$from, $to] = $this->period($request);
        $rows = StockAdjustment::with('stock')->betweenDates($from, $to)->latest('adjustment_date')->get();

        $pdf = \Pdf::loadView('stock-adjustments.pdf.journal', [
            'rows'      => $rows,
            'from'      => $from,
            'to'        => $to,
            'lossValue' => (float) $rows->where('type', 'perte')->sum('value_impact'),
            'gainValue' => (float) $rows->where('type', 'gain')->sum('value_impact'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('demarque-' . $from . '-' . $to . '.pdf');
    }

    /** Période demandée (défaut : mois courant). */
    private function period(Request $request): array
    {
        $from = $request->filled('from') ? $request->from : now()->startOfMonth()->toDateString();
        $to   = $request->filled('to') ? $request->to : now()->toDateString();

        return [$from, $to];
    }
}
