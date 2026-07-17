<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Harvest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Journal des récoltes du jour (terrain) — consultation mobile (Cultures).
 *
 * Récoltes saisies aujourd'hui, avec culture/variété, quantité et qualité, +
 * récap (nombre, poids net cumulé). Bornée à la ferme par FarmScope, lecture
 * cultures.L (+ verrou licence via Gate::before). Remplacement complet côté
 * client (comme /tasks).
 */
class HarvestJournalController extends Controller
{
    public function today(): JsonResponse
    {
        if (Gate::denies('cultures.L')) {
            abort(403, 'Lecture du module Cultures non autorisée.');
        }

        $harvests = Harvest::query()
            ->with('cropCycle:id,code,crop_name,variety')
            ->whereDate('harvest_date', today())
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'harvests' => $harvests->map(fn (Harvest $h) => [
                'id'         => $h->id,
                'crop'       => $h->cropCycle?->crop_name,
                'variety'    => $h->cropCycle?->variety,
                'cycle_code' => $h->cropCycle?->code,
                'quantity'   => (float) $h->quantity,
                'unit'       => $h->unit,
                'weight_kg'  => round($h->effective_weight_kg, 2),
                'quality'    => $h->quality,
            ])->values(),
            'summary' => [
                'count'           => $harvests->count(),
                'total_weight_kg' => round($harvests->sum(fn (Harvest $h) => $h->effective_weight_kg), 2),
            ],
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
