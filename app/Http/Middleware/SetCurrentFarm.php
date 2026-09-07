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
 * 1. Si session('current_farm_id') existe → utiliser
 * 2. Sinon → ferme par défaut de l'utilisateur (is_default = true)
 * 3. Sinon → première ferme de l'utilisateur
 *
 * CHANGER de site est un GESTE, pas un effet de bord : il passe par la route
 * dédiée `farms.switch` (FarmController::switchFarm), la seule que les deux
 * sélecteurs de l'interface utilisent.
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

        /*
         * ─── CHANGER DE SITE EST UN GESTE, PAS UN EFFET DE BORD ───
         *
         * Ici se trouvait un « switch de ferme via URL (?farm_id=X) ». Mais
         * `$request->has()` / `input()` lisent la requête ENTIÈRE — la chaîne de
         * requête ET LE CORPS. N'importe quel formulaire portant un champ
         * `farm_id` déplaçait donc l'utilisateur, en silence, avant même que le
         * contrôleur ne s'exécute.
         *
         * Deux formulaires de l'application en portent un, et c'est le site de
         * DESTINATION qu'ils nomment : « Muter vers un autre site » et « Mettre
         * à disposition ».
         *
         * Mesuré : un administrateur sur le site 2 soumet une mutation vers le
         * site 3 en oubliant la date. La mutation est REFUSÉE, l'agent ne bouge
         * pas — et le site courant passe quand même à 3. Tous les écrans
         * suivants (lots, stock, ventes, tableaux de bord) montrent l'autre
         * site, à la suite d'une action que l'application vient de refuser.
         *
         * ─── ET C'ÉTAIT LA MOINS-DISANTE DE DEUX DÉCLARATIONS ───
         *
         * `FarmController::switchFarm` — la route dédiée `farms.switch`, celle
         * que les DEUX sélecteurs de l'interface utilisent — porte la même règle
         * en mieux : elle vérifie le rattachement, PUIS `Farm::isUsable()`, et
         * refuse avec un message. Ce second contrôle avait été ajouté exprès,
         * parce qu'« on pouvait basculer dans un site DÉSACTIVÉ ou même
         * SUPPRIMÉ ». Ce bloc-ci l'ignorait : `?farm_id=` sur un site désactivé
         * y basculait quand même, rouvrant précisément le trou refermé à côté.
         *
         * Aucune vue, aucune route ne fabrique d'URL `?farm_id=` : ce bloc
         * n'avait pas d'appelant légitime — seulement des victimes.
         */

        // 1. Si pas encore de ferme en session → résoudre
        if (! session('current_farm_id')) {
            $this->resolveDefaultFarm($user);
        }

        /*
         * 2. Partager la ferme courante — SI elle est encore utilisable.
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

        // 3. Partager les fermes accessibles (pour le switcher)
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
