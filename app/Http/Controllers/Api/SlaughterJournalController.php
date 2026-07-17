<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SlaughterOrder;
use App\Support\JournalPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Journal d'abattage du jour (terrain) — consultation mobile.
 *
 * Ordres d'abattage prévus OU exécutés aujourd'hui, avec lot/client, effectifs
 * prévu/réel et statut ; + récap (abattus, prévus, bloqués, sujets abattus,
 * poids vif). Bornée à la ferme par FarmScope, lecture abattoir.L (+ verrou
 * licence via Gate::before). Remplacement complet côté client (comme /tasks).
 */
class SlaughterJournalController extends Controller
{
    public function today(Request $request): JsonResponse
    {
        if (Gate::denies('abattoir.L')) {
            abort(403, 'Lecture de l’Abattoir non autorisée.');
        }

        $period = JournalPeriod::resolve($request);
        $range = [$period['start'], $period['end']];

        $orders = SlaughterOrder::query()
            ->with(['batch:id,code', 'client:id,name'])
            ->where(fn ($q) => $q->whereBetween('planned_date', $range)->orWhereBetween('actual_date', $range))
            ->orderByDesc('created_at')
            ->get(['id', 'order_number', 'batch_id', 'client_id', 'planned_quantity', 'actual_quantity', 'total_live_weight_kg', 'status']);

        $done = $orders->where('status', 'termine');

        // Série journalière : sujets abattus par jour (date d'exécution).
        $buckets = JournalPeriod::dailyBuckets($period['start'], $period['end']);
        foreach ($done as $order) {
            $day = $order->actual_date?->toDateString();
            if ($day !== null && isset($buckets[$day])) {
                $buckets[$day] += (float) $order->actual_quantity;
            }
        }

        return response()->json([
            'orders' => $orders->map(fn (SlaughterOrder $order) => [
                'id'               => $order->id,
                'order_number'     => $order->order_number,
                'batch'            => $order->batch?->code,
                'client'           => $order->client?->name,
                'planned_quantity' => (int) $order->planned_quantity,
                'actual_quantity'  => $order->actual_quantity !== null ? (int) $order->actual_quantity : null,
                'status'           => $order->status,
            ])->values(),
            'summary' => [
                'total'            => $orders->count(),
                'done'             => $done->count(),
                'planned'          => $orders->where('status', 'planifie')->count(),
                'blocked'          => $orders->where('status', 'bloque')->count(),
                'slaughtered'      => (int) $done->sum('actual_quantity'),
                'live_weight_kg'   => (float) $done->sum('total_live_weight_kg'),
            ],
            'series'      => JournalPeriod::series($buckets),
            'period'      => ['key' => $period['key'], 'label' => $period['label']],
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
