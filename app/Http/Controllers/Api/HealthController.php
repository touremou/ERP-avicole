<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Sonde de santé publique de l'API v1 — sert au déploiement staging :
 *  - vérifier depuis la PWA (ou curl) que l'app parle à la BONNE API ;
 *  - confirmer que CORS laisse passer l'origine app.* (préflight + réponse) ;
 *  - exposer la version pour tracer ce que le pilote utilise réellement.
 *
 * Non authentifiée, volontairement minimale (aucune donnée métier).
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbOk = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbOk = false;
        }

        return response()->json([
            'status'      => $dbOk ? 'ok' : 'degraded',
            'app'         => config('app.name'),
            'api_version' => 'v1',
            'database'    => $dbOk ? 'up' : 'down',
            'server_time' => now()->toIso8601String(),
        ], $dbOk ? 200 : 503);
    }
}
