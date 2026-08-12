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

        /*
         * 3. Partager la ferme courante — SI elle est encore utilisable.
         *
         * C'était `Farm::withoutGlobalScopes()->find()`. Sur ce modèle, cet appel ne
         * retire que la protection des SUPPRESSIONS (Farm n'a pas de scope de ferme) :
         * un site supprimé restait donc affiché comme site courant, et le travail
         * continuait de s'y déverser. Le sélecteur, juste en dessous, l'excluait déjà —
         * les deux se contredisaient sur le même écran.
         *
         * Si le site en session n'est plus utilisable, on l'oublie et on en résout un
         * autre plutôt que de laisser l'utilisateur dans un site fantôme.
         */
        $currentFarmId = session('current_farm_id');

        if ($currentFarmId && ! \App\Models\Farm::isUsable((int) $currentFarmId)) {
            session()->forget('current_farm_id');
            $this->resolveDefaultFarm($user);
            $currentFarmId = session('current_farm_id');
        }

        if ($currentFarmId) {
            view()->share('currentFarm', \App\Models\Farm::find($currentFarmId));
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

        // Vue consolidée (S3) : réservée au PROPRIÉTAIRE de site (le promoteur,
        // qui n'a pas forcément le rôle « admin ») ou à l'administrateur. On le
        // partage ici pour ne pas afficher un lien qui redirigerait — is_owner
        // est déjà chargé ci-dessus, aucune requête de plus.
        view()->share(
            'canConsolidate',
            $userFarms->count() > 1
                && ($userFarms->contains(fn ($f) => (bool) $f->is_owner) || \Illuminate\Support\Facades\Gate::allows('admin.L')),
        );

        return $next($request);
    }

    private function resolveDefaultFarm($user): void
    {
        // Sites du compte encore EN SERVICE. Les deux requêtes ci-dessous lisaient le
        // pivot sans aucun filtre : un compte dont le site par défaut avait été
        // désactivé ou supprimé y était replacé à chaque requête.
        $usable = \App\Models\Farm::active()
            ->whereIn('id', DB::table('farm_user')->where('user_id', $user->id)->pluck('farm_id'))
            ->pluck('id');

        // Ferme par défaut, si elle est encore en service.
        $default = DB::table('farm_user')
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->whereIn('farm_id', $usable)
            ->value('farm_id');

        if ($default) {
            session(['current_farm_id' => $default]);
            return;
        }

        // Premier site en service parmi les siens.
        if ($usable->isNotEmpty()) {
            session(['current_farm_id' => $usable->first()]);
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
