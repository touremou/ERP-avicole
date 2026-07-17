<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MillProduction;
use App\Support\JournalPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Journal de production Provenderie du jour (terrain) — consultation mobile.
 *
 * Ordres de production (OP) du jour avec formule, quantité produite (kg) et
 * statut, + récap (produits / en cours / planifiés, total kg). Bornée à la
 * ferme par FarmScope, lecture provenderie.L (+ verrou licence via
 * Gate::before). Remplacement complet côté client (comme /tasks).
 */
class MillJournalController extends Controller
{
    public function today(Request $request): JsonResponse
    {
        if (Gate::denies('provenderie.L')) {
            abort(403, 'Lecture de la Provenderie non autorisée.');
        }

        $period = JournalPeriod::resolve($request);

        $productions = MillProduction::query()
            ->with('formula:id,name')
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->orderByDesc('created_at')
            ->get(['id', 'batch_number', 'formula_id', 'quantity_produced', 'status', 'started_at', 'created_at']);

        $done = $productions->where('status', 'Terminé');

        return response()->json([
            'productions' => $productions->map(fn (MillProduction $op) => [
                'id'                => $op->id,
                'batch_number'      => $op->batch_number,
                'formula'           => $op->formula?->name,
                'quantity_produced' => (float) $op->quantity_produced,
                'status'            => $op->status,
                'started_at'        => $op->started_at?->toIso8601String(),
                'created_at'        => $op->created_at?->toIso8601String(),
            ])->values(),
            'summary' => [
                'total'       => $productions->count(),
                'done'        => $done->count(),
                'in_progress' => $productions->where('status', 'En cours')->count(),
                'planned'     => $productions->where('status', 'Planifié')->count(),
                'total_kg'    => (float) $done->sum('quantity_produced'),
            ],
            'period'      => ['key' => $period['key'], 'label' => $period['label']],
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
