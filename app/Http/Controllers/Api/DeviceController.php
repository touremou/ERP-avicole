<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestion des appareils connectés (tokens Sanctum par device).
 *
 * Un token = un appareil (device_name fourni au login). L'utilisateur peut
 * lister ses appareils et en révoquer un — le cas d'usage terrain critique
 * étant le TÉLÉPHONE PERDU : on coupe l'accès de l'appareil depuis un autre,
 * sans changer le mot de passe.
 *
 * L'appareil COURANT ne peut pas s'auto-révoquer ici (guard 422) : la voie
 * normale est POST /auth/logout — évite qu'un client bugué se déconnecte
 * en croyant révoquer un autre appareil. Cf. docs/mobile/phase-0-spec.md §3.4.
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;

        $devices = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($token) => [
                'id'           => $token->id,
                'name'         => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at'   => $token->created_at?->toIso8601String(),
                'current'      => $token->id === $currentId,
            ])
            ->values();

        return response()->json([
            'devices'     => $devices,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, int $deviceId): JsonResponse
    {
        // Borné aux tokens de l'utilisateur : on ne peut jamais révoquer
        // l'appareil de quelqu'un d'autre (404 plutôt que 403 — ne révèle
        // pas l'existence du token).
        $token = $request->user()->tokens()->find($deviceId);

        if (! $token) {
            return response()->json(['message' => 'Appareil introuvable.'], 404);
        }

        if ($token->id === $request->user()->currentAccessToken()->id) {
            return response()->json([
                'message' => 'Impossible de révoquer l\'appareil courant — utilisez la déconnexion.',
            ], 422);
        }

        $token->delete();

        return response()->json(['message' => 'Appareil révoqué.']);
    }
}
