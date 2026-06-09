<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckDatabaseConnection
{
    public function handle($request, Closure $next)
    {
        try {
            // Tentative de connexion ultra-rapide
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            // SI ÉCHEC : On informe le système qu'on est en mode dégradé
            config(['app.database_down' => true]);
            
            // On bypass l'authentification SQL pour les requêtes déjà cachées par le SW
            if ($request->isMethod('get')) {
                return $next($request);
            }
        }

        return $next($request);
    }
}