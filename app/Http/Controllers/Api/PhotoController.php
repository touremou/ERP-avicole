<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Téléversement de photos terrain (incident sanitaire, reçu de dépense…).
 *
 * Le mobile stocke la photo en local (Dexie) tant qu'il est hors-ligne, puis
 * la téléverse ICI au retour réseau — AVANT de pousser l'opération de sync
 * qui la référence (payload.photo_path = le chemin renvoyé). Découplé de la
 * sync : une photo orpheline (op refusée ensuite) est inoffensive et
 * nettoyable, l'inverse (op sans sa photo) ne l'est pas.
 */
class PhotoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Toute photo terrain accompagne une écriture élevage OU abattoir
        // (certificat sanitaire de réception, preuve de nettoyage HACCP).
        if (Gate::denies('elevage.C') && Gate::denies('abattoir.C')) {
            return response()->json(['message' => __('Permission insuffisante.')], 403);
        }

        $validated = $request->validate([
            // 5 Mo max : les clients compressent avant envoi (règle data faible).
            'photo'   => 'required|image|max:5120',
            'context' => 'nullable|string|in:incident,expense,daily_check,reception,cleaning',
        ]);

        $folder = 'field/' . ($validated['context'] ?? 'incident');
        $path = $request->file('photo')->store($folder, 'public');

        return response()->json([
            'path'        => $path,
            'url'         => asset('storage/' . $path),
            'server_time' => now()->toIso8601String(),
        ], 201);
    }
}
