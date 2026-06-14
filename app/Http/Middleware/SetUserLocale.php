<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique la langue d'interface choisie par l'utilisateur connecté
 * (colonne users.locale). Sans choix explicite, la langue par défaut
 * de l'application (APP_LOCALE) reste en vigueur.
 */
class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? $request->user('sanctum');

        $locale = $user?->locale;

        if ($locale && in_array($locale, config('app.supported_locales', ['fr', 'en']), true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
