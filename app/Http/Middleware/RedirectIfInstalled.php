<?php

namespace App\Http\Middleware;

use App\Support\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RedirectIfInstalled — Empêche l'accès à l'assistant d'installation une fois
 * l'application déjà installée.
 *
 * Le témoin n'est plus le seul fichier `storage/installed` : ce fichier ne
 * voyage pas avec l'application (le déploiement exclut `storage/` du rsync,
 * délibérément), si bien qu'un nouvel hôte branché sur une base déjà pleine
 * rouvrait l'assistant au premier visiteur venu — lequel pouvait REPRENDRE le
 * compte administrateur. Cf. App\Support\InstallationState.
 */
class RedirectIfInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (InstallationState::estInstallee()) {
            return redirect('/login');
        }

        return $next($request);
    }
}
