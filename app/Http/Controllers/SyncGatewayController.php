<?php

namespace App\Http\Controllers;

use App\Services\Sync\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Passerelle LEGACY de synchro offline (session web) → SyncService.
 *
 * @deprecated Porte de compatibilité pour l'actuel resources/js/sync-engine.js
 * (Dexie), qui poste sur /api/sync/* en session authentifiée. Depuis la fusion
 * de l'audit A2, TOUTE la logique vit dans App\Services\Sync\SyncService — ce
 * contrôleur ne fait que traduire l'ancien contrat HTTP :
 *   validation_failed → 422 (payload Laravel standard)
 *   permission_denied → 403
 *   success / already_synced / conflict → 200 avec le corps du service
 * À SUPPRIMER quand la PWA basculera sur l'API v1 (/api/v1/sync/push),
 * cf. docs/mobile/phase-0-spec.md.
 */
class SyncGatewayController extends Controller
{
    public function reconcile(Request $request, SyncService $sync): JsonResponse
    {
        return $this->respond($sync->handle('batch.upsert', $request->all()));
    }

    public function reconcileDailyCheck(Request $request, SyncService $sync): JsonResponse
    {
        return $this->respond($sync->handle('daily_check.create', $request->all()));
    }

    public function reconcileEggCollection(Request $request, SyncService $sync): JsonResponse
    {
        return $this->respond($sync->handle('egg_collection.create', $request->all()));
    }

    public function reconcileStockMovement(Request $request, SyncService $sync): JsonResponse
    {
        return $this->respond($sync->handle('stock_movement.create', $request->all()));
    }

    public function reconcileSale(Request $request, SyncService $sync): JsonResponse
    {
        return $this->respond($sync->handle('sale.create', $request->all()));
    }

    public function reconcileExpense(Request $request, SyncService $sync): JsonResponse
    {
        return $this->respond($sync->handle('expense.create', $request->all()));
    }

    /** Traduit les statuts du SyncService vers l'ancien contrat HTTP. */
    private function respond(array $result): JsonResponse
    {
        return match ($result['status']) {
            'validation_failed' => response()->json([
                'message' => 'Données invalides.',
                'errors'  => $result['errors'] ?? [],
            ], 422),
            'permission_denied' => response()->json([
                'status'  => 'error',
                'message' => $result['message'] ?? 'Permission insuffisante.',
            ], 403),
            'error' => response()->json([
                'status'  => 'error',
                'message' => $result['message'] ?? 'Erreur interne.',
            ], 500),
            default => response()->json($result), // success | already_synced | conflict
        };
    }
}
