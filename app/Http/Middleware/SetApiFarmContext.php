<?php

namespace App\Http\Middleware;

use App\Models\Farm;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetApiFarmContext — Contexte ferme pour l'API (Sanctum, stateless).
 *
 * POURQUOI (audit 360° — étanchéité multi-fermes) : FarmScope et l'auto-fill
 * de BelongsToFarm reposent sur session('current_farm_id'), alimentée par
 * SetCurrentFarm… qui n'est attaché qu'au groupe WEB. Sur l'API, la session
 * n'était jamais peuplée : AUCUN filtre ferme en lecture (fuite inter-sites
 * via /api/v1/batches) et écritures rattachées à la ferme PAR DÉFAUT au lieu
 * de celle de l'utilisateur.
 *
 * Ce middleware peuple session('current_farm_id') pour la DURÉE DE LA
 * REQUÊTE (le store n'est pas persisté : pas de StartSession sur l'API),
 * ce qui réactive à l'identique FarmScope + auto-fill, sans dupliquer la
 * logique de scope.
 *
 * Résolution (miroir API de SetCurrentFarm) :
 *   1. En-tête X-Farm-Id → vérifié contre farm_user (403 sinon) ;
 *   2. ferme par défaut de l'utilisateur (farm_user.is_default) ;
 *   3. première ferme de l'utilisateur ;
 *   4. repli mono-ferme : Farm::defaultId() (aucune affectation pivot).
 *
 * À attacher APRÈS auth:sanctum (l'utilisateur doit être résolu).
 */
class SetApiFarmContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request); // auth:sanctum a déjà refusé en amont
        }

        // Fermes réellement affectées à l'utilisateur (pivot farm_user).
        $userFarmIds = DB::table('farm_user')
            ->where('user_id', $user->id)
            ->pluck('farm_id')
            ->map(fn ($id) => (int) $id);

        // 1. Choix explicite de ferme par l'appareil (multi-sites).
        //    Le contrôle d'accès (403) ne vaut QUE pour un utilisateur qui a
        //    des affectations : il ne peut alors sortir de son périmètre. Un
        //    utilisateur SANS aucune affectation (mono-ferme, ou admin créé
        //    hors assistant) n'est pas bloqué — il retombe sur le repli site
        //    ci-dessous, exactement comme le fait SetCurrentFarm côté web.
        if ($request->hasHeader('X-Farm-Id') && $userFarmIds->isNotEmpty()) {
            $requested = (int) $request->header('X-Farm-Id');

            if (! $userFarmIds->contains($requested)) {
                return response()->json([
                    'message' => "Accès refusé à cette ferme.",
                ], 403);
            }

            session(['current_farm_id' => $requested]);

            return $next($request);
        }

        // 2-3. Ferme par défaut puis première ferme de l'utilisateur.
        // 4. Repli mono-ferme (aucune affectation) : ferme par défaut du site.
        $farmId = $userFarmIds->isNotEmpty()
            ? DB::table('farm_user')
                ->where('user_id', $user->id)
                ->orderByDesc('is_default')
                ->value('farm_id')
            : null;

        session(['current_farm_id' => $farmId ?: Farm::defaultId()]);

        return $next($request);
    }
}
