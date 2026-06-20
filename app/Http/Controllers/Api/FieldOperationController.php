<?php

namespace App\Http\Controllers\Api;

use App\Actions\DailyCheck\RecordDailyCheck;
use App\Actions\EggProduction\RecordEggCollection;
use App\Http\Controllers\Controller;
use App\Http\Requests\DailyCheck\StoreDailyCheckRequest;
use App\Http\Requests\EggProduction\StoreEggProductionRequest;
use Illuminate\Http\JsonResponse;

/**
 * Opérations terrain critiques pour l'application mobile : pointage
 * journalier (mortalité, aliment, eau…) et collecte d'œufs.
 *
 * Réutilise les FormRequests et Actions métier du web : mêmes règles de
 * validation, mêmes Gates, mêmes effets (stock aliment décrémenté par
 * RecordDailyCheck, taux de ponte recalculé par RecordEggCollection).
 */
class FieldOperationController extends Controller
{
    public function storeDailyCheck(StoreDailyCheckRequest $request, RecordDailyCheck $action): JsonResponse
    {
        $check = $action->execute($request->validated());

        return response()->json([
            'message' => 'Pointage enregistré.',
            'data' => $check->fresh(),
        ], 201);
    }

    public function storeEggCollection(StoreEggProductionRequest $request, RecordEggCollection $action): JsonResponse
    {
        $production = $action->execute($request->validated());

        return response()->json([
            'message' => 'Collecte enregistrée.',
            'data' => $production->fresh(),
        ], 201);
    }
}
