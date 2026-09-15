<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte suspendu n'a plus d'accès — à chaque requête, pas seulement au login.
 *
 * `is_active` n'était vérifié qu'à la CONNEXION : `LoginRequest` pour le web,
 * `Api\AuthController` pour le mobile. Rien ne le revérifiait ensuite. Suspendre
 * un compte bloquait donc les connexions FUTURES et laissait intact tout ce qui
 * était déjà ouvert — la session du navigateur jusqu'à expiration du cookie, et
 * le jeton du téléphone sans limite.
 *
 * Les jetons sont désormais révoqués au moment du geste (cf. UserController).
 * Ce middleware couvre ce que la révocation ne peut pas couvrir : la session web,
 * qui ne porte aucun jeton, et le délai entre deux gestes d'administration.
 *
 * ─── POURQUOI DÉCONNECTER, ET PAS SEULEMENT REFUSER ───
 *
 * Laisser la session vivante en refusant chaque page produirait une boucle de
 * refus sans issue. On termine la session et on renvoie à l'écran de connexion
 * avec le motif — où `LoginRequest` refusera à son tour, en le disant.
 *
 * Les requêtes d'API, elles, reçoivent un 401 : c'est ce que la file de synchro
 * du terrain sait interpréter.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Pas d'utilisateur : les routes publiques et l'écran de connexion
        // passent, et `auth` s'occupe déjà des routes protégées.
        if (! $user || $user->isActive()) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => __('Ce compte est suspendu.'),
            ], 401);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('error', __('Ce compte est suspendu. Contactez un administrateur.'));
    }
}
