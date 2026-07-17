<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetCurrentFarm — Définit la ferme active pour la requête en cours.
 *
 * Logique de résolution :
 * 1. Si ?farm_id=X dans l'URL → switch vers cette ferme (si autorisé)
 * 2. Si session('current_farm_id') existe → utiliser
 * 3. Sinon → ferme par défaut de l'utilisateur (is_default = true)
 * 4. Sinon → première ferme de l'utilisateur
 *
 * ENREGISTREMENT dans bootstrap/app.php :
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->web(append: [
 *           \App\Http\Middleware\SetCurrentFarm::class,
 *       ]);
 *   })
 */
class SetCurrentFarm
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();

        // 1. Switch de ferme via URL (?farm_id=X)
        if ($request->has('farm_id')) {
            $requestedFarmId = (int) $request->input('farm_id');

            // Vérifier que l'utilisateur a accès à cette ferme
            $hasAccess = DB::table('farm_user')
                ->where('user_id', $user->id)
                ->where('farm_id', $requestedFarmId)
                ->exists();

            if ($hasAccess) {
                session(['current_farm_id' => $requestedFarmId]);
            }
        }

        // 2. Si pas encore de ferme en session → résoudre
        if (! session('current_farm_id')) {
            $this->resolveDefaultFarm($user);
        }

        // 3. Partager la ferme courante avec toutes les vues
        $currentFarmId = session('current_farm_id');
        if ($currentFarmId) {
            $currentFarm = \App\Models\Farm::withoutGlobalScopes()->find($currentFarmId);
            view()->share('currentFarm', $currentFarm);
            view()->share('currentFarmId', $currentFarmId);
        }

        // 4. Partager les fermes accessibles (pour le switcher)
        $userFarms = DB::table('farm_user')
            ->join('farms', 'farms.id', '=', 'farm_user.farm_id')
            ->where('farm_user.user_id', $user->id)
            ->where('farms.is_active', true)
            ->whereNull('farms.deleted_at')
            ->select('farms.*', 'farm_user.is_default', 'farm_user.is_owner')
            ->get();

        view()->share('userFarms', $userFarms);
        view()->share('isMultiFarm', $userFarms->count() > 1);

        return $next($request);
    }

    private function resolveDefaultFarm($user): void
    {
        // Ferme par défaut
        $default = DB::table('farm_user')
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->value('farm_id');

        if ($default) {
            session(['current_farm_id' => $default]);
            return;
        }

        // Première ferme disponible
        $first = DB::table('farm_user')
            ->where('user_id', $user->id)
            ->value('farm_id');

        if ($first) {
            session(['current_farm_id' => $first]);
            return;
        }

        // Repli mono-ferme (aucune affectation pivot) : ferme par défaut du
        // site — miroir de SetApiFarmContext. ÉTANCHÉITÉ : sans ce repli, un
        // utilisateur authentifié sans affectation n'aurait AUCUNE ferme en
        // session → FarmScope ne filtrerait plus rien (fuite inter-fermes en
        // « fail-open »). On borne toujours à une ferme, jamais « toutes ».
        $default = \App\Models\Farm::defaultId();
        if ($default) {
            session(['current_farm_id' => $default]);
        }
    }
}
