<?php

namespace App\Providers\RouteServiceProvider; // Vérifiez votre namespace réel

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User; // <--- N'oubliez pas d'importer le modèle User

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$guards)
    {
        // 1. Vérification : Si aucun utilisateur n'existe dans la base, on force le setup
        if (User::count() === 0) {
            return redirect()->route('setup.index');
        }

        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                return redirect(RouteServiceProvider::HOME);
            }
        }

        return $next($request);
    }
}