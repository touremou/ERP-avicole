<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Support\JournalPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Journal des ventes du jour (terrain) — consultation mobile.
 *
 * Renvoie les ventes du jour de la ferme courante (toutes, y compris POS web
 * et autres appareils) avec un récapitulatif (nombre, CA, encaissé, dû).
 * Bornée à la ferme par FarmScope (BelongsToFarm). Lecture commerce.L —
 * le verrou licence s'applique aussi (Gate::before). Remplacement complet
 * côté client (comme /tasks) : pas de delta à gérer.
 */
class SaleJournalController extends Controller
{
    public function today(Request $request): JsonResponse
    {
        if (Gate::denies('commerce.L')) {
            abort(403, 'Lecture du module Commerce non autorisée.');
        }

        $period = JournalPeriod::resolve($request);

        $sales = Sale::query()
            ->with('client:id,name')
            ->whereBetween('sale_date', [$period['start'], $period['end']])
            ->orderByDesc('created_at')
            ->get([
                'id', 'reference', 'client_id', 'type', 'status',
                'total_amount', 'paid_amount', 'payment_status', 'sale_date', 'created_at',
            ]);

        // Récapitulatif : le CA ne compte que les ventes engagées (validées /
        // livrées), les brouillons/annulées n'entrent pas dans le chiffre.
        $counted = $sales->whereIn('status', ['valide', 'livre']);

        // Série journalière : CA par jour (ventes engagées) sur la plage.
        $buckets = JournalPeriod::dailyBuckets($period['start'], $period['end']);
        foreach ($counted as $sale) {
            $day = $sale->sale_date?->toDateString();
            if ($day !== null && isset($buckets[$day])) {
                $buckets[$day] += (float) $sale->total_amount;
            }
        }

        return response()->json([
            'sales' => $sales->map(fn (Sale $sale) => [
                'id'             => $sale->id,
                'reference'      => $sale->reference,
                'client_name'    => $sale->client?->name,
                'type'           => $sale->type,
                'status'         => $sale->status,
                'total_amount'   => (float) $sale->total_amount,
                'paid_amount'    => (float) $sale->paid_amount,
                'remaining'      => (float) $sale->remaining_amount,
                'payment_status' => $sale->payment_status,
                'created_at'     => $sale->created_at?->toIso8601String(),
            ])->values(),
            'summary' => [
                'count'     => $counted->count(),
                'total'     => (float) $counted->sum('total_amount'),
                'paid'      => (float) $counted->sum('paid_amount'),
                'remaining' => (float) $counted->sum(fn (Sale $s) => $s->remaining_amount),
            ],
            'series'      => JournalPeriod::series($buckets),
            'period'      => ['key' => $period['key'], 'label' => $period['label']],
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
