<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    //public function handle($request, Closure $next)
    //{
    //    if (auth()->check() && auth()->user()->role === 'admin') {
    //        return $next($request);
     //   }
        
        // Redirige vers le dashboard (qui n'est pas dans le groupe admin)
     //   return redirect('/dashboard'); 
    //}

    public function handle(Request $request, Closure $next)
{
    if (auth()->check() && auth()->user()->hasPermission('L')) {
        return $next($request);
    }

    // On redirige manuellement si le middleware échoue
    return redirect()->route('dashboard')->with('error', '🔒 Accès réservé aux administrateurs.');
}
}
