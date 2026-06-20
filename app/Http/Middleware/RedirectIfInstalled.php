<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RedirectIfInstalled — Empêche l'accès à l'assistant d'installation une
 * fois l'application déjà installée (storage/installed présent).
 */
class RedirectIfInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (file_exists(storage_path('installed'))) {
            return redirect('/login');
        }

        return $next($request);
    }
}
