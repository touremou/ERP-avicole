<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Force les requêtes à être traitées comme des appels JSON, pour que le
 * middleware "auth" renvoie une 401 JSON (au lieu d'une redirection vers
 * /login) et n'enregistre jamais ces URLs comme "intended" — sinon
 * l'utilisateur est renvoyé sur /api/... après s'être reconnecté.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
