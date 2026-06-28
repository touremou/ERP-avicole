<?php

namespace App\Http\Middleware;

use App\Services\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureLicensed — verrouille l'application quand l'abonnement est expiré
 * (hors période de grâce) ou absent, en redirigeant vers l'écran d'activation.
 *
 * INACTIF par défaut : ne fait rien tant que le système de licence n'est pas
 * armé (clé publique posée + enforcement activé). N'enforce que pour les
 * sessions authentifiées et laisse toujours passer les routes indispensables
 * (connexion, activation de licence, installeur, traçabilité publique, santé).
 */
class EnsureLicensed
{
    public function __construct(private LicenseService $licenses) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->licenses->isEnabled()) {
            return $next($request);
        }

        // Routes toujours accessibles (sinon boucle de redirection).
        if ($request->routeIs('license.*', 'login', 'logout', 'password.*', 'install.*', 'trace.*')
            || $request->is('up')) {
            return $next($request);
        }

        // On n'enforce que les sessions connectées : un visiteur non authentifié
        // doit pouvoir atteindre la page de connexion.
        if (! $request->user()) {
            return $next($request);
        }

        // Borne anti-recul d'horloge (mémorise l'instant courant).
        $this->licenses->touchClock();

        if ($this->licenses->shouldBlock()) {
            // Les requêtes API/JSON reçoivent un 402 explicite plutôt qu'une redirection.
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Abonnement expiré. Veuillez renouveler votre licence.'], 402);
            }

            return redirect()->route('license.edit');
        }

        return $next($request);
    }
}
