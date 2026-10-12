<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UN APPEL D'ARRIÈRE-PLAN NE MANGE PAS LES MESSAGES DE LA PAGE.
 *
 * Un message flashé (« Saisie refusée », « Achat enregistré », les erreurs
 * d'un formulaire) vit une requête : la suivante le lit, puis il disparaît.
 * Or les pages lancent des appels JSON en arrière-plan sur la même session —
 * le rafraîchissement des données du mode terrain (`/api/offline/*`), les
 * pastilles, la synchro. Rejoué dans Chromium : un formulaire refusé, puis
 * `GET /api/offline/batches` (parti de la page précédente) passe AVANT la page
 * de retour… et consomme le message. L'écran revenait sans erreur ni
 * confirmation, et la fenêtre de saisie ne se rouvrait pas.
 *
 * Un appel d'arrière-plan n'affiche rien : il prolonge les messages pour la
 * prochaine vraie page au lieu de les consommer.
 */
class KeepFlashForBackgroundRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->hasSession() && $this->estEnArrierePlan($request)) {
            $request->session()->reflash();
        }

        return $response;
    }

    private function estEnArrierePlan(Request $request): bool
    {
        // Une navigation (lien, formulaire, retour) affiche les messages ;
        // un fetch/XHR, non.
        if ($request->headers->get('Sec-Fetch-Mode') === 'navigate') {
            return false;
        }

        return $request->expectsJson() || $request->ajax() || $request->is('api/*');
    }
}
