<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lecture des lots pour l'application mobile (opérations terrain).
 */
class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_if(Gate::denies('elevage.L'), 403, 'Lecture du module Élevage non autorisée.');

        $batches = Batch::with(['building:id,name', 'species:id,name'])
            ->when($request->query('status', 'Actif') !== 'all',
                fn ($q) => $q->where('status', $request->query('status', 'Actif')))
            ->orderByDesc('arrival_date')
            ->get([
                'id', 'uuid', 'code', 'type', 'status', 'building_id', 'species_id',
                'initial_quantity', 'current_quantity', 'qty_dead', 'arrival_date',
            ]);

        return response()->json(['data' => $batches]);
    }

    public function show(Batch $batch): JsonResponse
    {
        abort_if(Gate::denies('elevage.L'), 403, 'Lecture du module Élevage non autorisée.');

        $batch->load(['building:id,name', 'species:id,name']);

        return response()->json([
            'data' => $batch,
            'last_check' => $batch->dailyChecks()->latest('check_date')->first(),
        ]);
    }

    /**
     * Fiche enrichie d'un lot : indicateurs de performance + historique des
     * pointages (série de poids, mortalité, aliment). Consultation terrain —
     * elevage.L, borné à la ferme par FarmScope (liaison de route).
     */
    public function history(Batch $batch): JsonResponse
    {
        abort_if(Gate::denies('elevage.L'), 403, 'Lecture du module Élevage non autorisée.');

        // Série chronologique (ancien → récent) pour la courbe et l'historique.
        $checks = $batch->dailyChecks()
            ->orderBy('check_date')
            ->get(['check_date', 'avg_weight', 'mortality', 'feed_consumed', 'water_consumed', 'health_status'])
            ->map(fn ($c) => [
                'date'       => $c->check_date?->toDateString(),
                'weight'     => $c->avg_weight !== null ? (float) $c->avg_weight : null,
                'mortality'  => (int) $c->mortality,
                'feed'       => $c->feed_consumed !== null ? (float) $c->feed_consumed : null,
                'water'      => $c->water_consumed !== null ? (float) $c->water_consumed : null,
                'health'     => $c->health_status,
            ]);

        // GMQ (g/jour) : gain entre le poids de départ et le dernier poids pesé,
        // rapporté à l'âge du lot. Null si données insuffisantes.
        $weighed = $checks->filter(fn ($c) => $c['weight'] !== null && $c['weight'] > 0);
        $startWeight = $batch->avg_weight_start ? (float) $batch->avg_weight_start : ($weighed->first()['weight'] ?? null);
        $latestWeight = $weighed->last()['weight'] ?? null;
        $gmq = ($startWeight && $latestWeight && $batch->age > 0)
            ? round((($latestWeight - $startWeight) * 1000) / $batch->age, 1)
            : null;

        return response()->json([
            'batch' => [
                'id'               => $batch->id,
                'code'             => $batch->code,
                'status'           => $batch->status,
                'building'         => $batch->building?->name,
                'age'              => $batch->age,
                'initial_quantity' => (int) $batch->initial_quantity,
                'current_quantity' => (int) $batch->current_quantity,
                'total_mortality'  => (int) $batch->total_mortality,
                'mortality_rate'   => round((float) $batch->mortality_rate, 2),
                'avg_weight_start' => $startWeight,
                'latest_weight'    => $latestWeight,
                'gmq'              => $gmq,
                'is_gmq_tracked'   => $batch->isGmqTracked(),
            ],
            'checks'      => $checks->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
