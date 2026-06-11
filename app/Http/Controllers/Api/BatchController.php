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
}
