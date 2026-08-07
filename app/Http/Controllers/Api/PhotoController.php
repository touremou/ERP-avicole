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
    /**
     * Droit exigé pour chaque contexte de photo — déclaration UNIQUE, qui sert
     * à la fois de liste de validation et de contrôle d'accès. Les deux ne
     * peuvent donc plus diverger : ajouter un contexte sans lui donner de droit
     * ne compile pas dans la tête du lecteur, et un test le vérifie.
     *
     * Les niveaux reprennent ceux des opérations de synchro correspondantes
     * (cf. mobile/src/offline/access.ts) : la photo accompagne une écriture, et
     * n'a pas à être plus exigeante qu'elle.
     */
    private const CONTEXT_ABILITIES = [
        'incident'    => 'elevage.C',
        'daily_check' => 'elevage.C',
        'reception'   => 'abattoir.C',
        'cleaning'    => 'abattoir.C',
        'expense'     => 'depenses.C',
        // Preuve de tâche : la tâche est déjà réservée à son assigné côté
        // terrain ; exiger un droit de module en plus fermerait la preuve à
        // l'ouvrier à qui la tâche est confiée.
        'task'        => 'elevage.L',
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // 5 Mo max : les clients compressent avant envoi (règle data faible).
            'photo'   => 'required|image|max:5120',
            'context' => 'nullable|string|in:' . implode(',', array_keys(self::CONTEXT_ABILITIES)),
        ]);

        $context = $validated['context'] ?? 'incident';

        // Le droit exigé suit le CONTEXTE de la photo. Auparavant la porte
        // n'acceptait que `elevage.C` ou `abattoir.C`, alors que l'endpoint
        // déclarait déjà six contextes : le reçu de carburant d'une dépense et la
        // preuve d'une tâche étaient donc refusés à qui n'avait pas de droit
        // d'élevage — et refusés en SILENCE.
        //
        // Le silence est le vrai défaut. Un téléversement refusé fait sauter
        // l'opération du tour de synchro (cf. mobile/src/offline/sync.ts) : elle
        // reste en file, réessayée à chaque passage, sans jamais apparaître dans
        // « À corriger » ni faire redescendre le compteur. Le technicien voit
        // « enregistré », et la dépense n'arrive jamais en comptabilité.
        if (Gate::denies(self::CONTEXT_ABILITIES[$context])) {
            return response()->json(['message' => __('Permission insuffisante.')], 403);
        }

        $folder = 'field/' . $context;
        $path = $request->file('photo')->store($folder, 'public');

        return response()->json([
            'path'        => $path,
            'url'         => asset('storage/' . $path),
            'server_time' => now()->toIso8601String(),
        ], 201);
    }
}
