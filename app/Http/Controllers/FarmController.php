<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FarmController extends Controller
{
    public function index()
    {
        if (Gate::denies('admin.S')) return redirect()->route('dashboard')->with('error', 'Accès réservé.');

        // On garde le scope SoftDeletes : `withoutGlobalScopes()` l'emportait
        // aussi, si bien qu'un site supprimé serait resté affiché dans la liste —
        // avec ses boutons, comme s'il existait encore. Les sites DÉSACTIVÉS, eux,
        // doivent rester visibles : c'est de là qu'on les réactive.
        $farms = Farm::withCount('users')->get();
        $users = User::orderBy('name')->get();

        return view('farms.index', compact('farms', 'users'));
    }

    public function store(Request $request)
    {
        if (Gate::denies('admin.S')) return back()->with('error', 'Non autorisé.');

        // Limite de fermes du plan d'abonnement (0 / système inactif = illimité).
        $licenses = app(\App\Services\LicenseService::class);
        if (! $licenses->allowsMore('max_farms', Farm::count())) {
            return back()->with('error', "Limite de fermes/sites de votre abonnement atteinte ({$licenses->limit('max_farms')}). Contactez le fournisseur pour l'augmenter.");
        }

        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'code'         => 'required|string|max:20|unique:farms,code',
            'address'      => 'nullable|string|max:500',
            'city'         => 'nullable|string|max:100',
            'region'       => 'nullable|string|max:100',
            'phone'        => 'nullable|string|max:30',
            'email'        => 'nullable|email|max:255',
            'manager_name' => 'nullable|string|max:255',
        ]);

        $farm = Farm::create($validated);

        // Assigner l'utilisateur courant comme propriétaire
        DB::table('farm_user')->insert([
            'farm_id'    => $farm->id,
            'user_id'    => Auth::id(),
            'is_default' => false,
            'is_owner'   => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', "Ferme \"{$farm->name}\" ({$farm->code}) créée.");
    }

    public function update(Request $request, Farm $farm)
    {
        if (Gate::denies('admin.S')) return back()->with('error', 'Non autorisé.');

        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'address'      => 'nullable|string|max:500',
            'city'         => 'nullable|string|max:100',
            'region'       => 'nullable|string|max:100',
            'phone'        => 'nullable|string|max:30',
            'manager_name' => 'nullable|string|max:255',
        ]);

        // La localisation a-t-elle changé ? (impacte le géocodage météo)
        $locationChanged = $farm->city !== ($validated['city'] ?? null)
            || $farm->region !== ($validated['region'] ?? null);

        $farm->update($validated);

        // Ville/région modifiée → on invalide les coordonnées GPS mémorisées et
        // les caches météo pour que la prochaine récupération re-géocode.
        if ($locationChanged) {
            $settings = $farm->settings ?? [];
            unset($settings['geo']);
            $farm->forceFill(['settings' => $settings])->save();

            \Illuminate\Support\Facades\Cache::forget("weather.current.farm.{$farm->id}");
            for ($d = 1; $d <= 7; $d++) {
                \Illuminate\Support\Facades\Cache::forget("weather.forecast.farm.{$farm->id}.{$d}");
            }
        }

        return back()->with('success', "Ferme \"{$farm->name}\" mise à jour.");
    }

    /**
     * ACTIVER / DÉSACTIVER un site.
     *
     * `is_active` existait DÉJÀ : le sélecteur de site, la vue consolidée et les
     * contrôles planifiés l'honorent tous. Mais aucun écran ne permettait de
     * l'écrire — un état appliqué partout que personne ne pouvait poser. Même
     * famille de défaut que le nom d'exploitation : des lecteurs, pas de rédacteur.
     *
     * Désactiver ne détruit RIEN et reste réversible : le site quitte les
     * sélecteurs, l'historique demeure lisible. C'est le geste attendu pour un
     * site qu'on ferme, qu'on met en sommeil entre deux campagnes, ou qu'on a créé
     * en double.
     */
    public function toggleActive(Farm $farm)
    {
        if (Gate::denies('admin.S')) return back()->with('error', 'Non autorisé.');

        // RÉACTIVER est toujours sûr.
        if (! $farm->is_active) {
            $farm->update(['is_active' => true]);

            return back()->with('success', "Site « {$farm->name} » réactivé.");
        }

        // Désactiver le DERNIER site actif enfermerait l'utilisateur dehors : plus
        // aucune ferme à sélectionner, donc plus de contexte, donc plus d'écrans.
        $othersActive = Farm::active()->where('id', '!=', $farm->id)->count();

        if ($othersActive === 0) {
            return back()->with('error',
                "« {$farm->name} » est le dernier site actif : le désactiver rendrait "
                . "l'application inutilisable. Activez un autre site d'abord."
            );
        }

        $farm->update(['is_active' => false]);

        // Si c'était le site COURANT, la session pointerait sur un site que plus
        // rien ne sert : on bascule, et on le dit. Laisser la session dessus
        // produirait des écrans vides sans explication.
        $switched = '';
        if ((int) session('current_farm_id') === (int) $farm->id) {
            $fallback = Farm::active()->orderBy('id')->first();
            session(['current_farm_id' => $fallback->id]);
            $switched = " Vous travaillez maintenant sur « {$fallback->name} ».";
        }

        // On ANNONCE ce qui reste en cours sur ce site plutôt que de bloquer :
        // désactiver est réversible, mais l'ignorer laisserait des lots vivants
        // hors de toute surveillance.
        $live = \App\Models\Batch::withoutGlobalScopes()
            ->where('farm_id', $farm->id)->where('status', 'Actif')->count();

        $warning = $live > 0
            ? " ⚠️ {$live} lot(s) encore ACTIF(s) y sont rattachés : ils ne seront plus suivis."
            : '';

        return back()->with('success', "Site « {$farm->name} » désactivé.{$switched}{$warning}");
    }

    /**
     * SUPPRIMER un site — uniquement s'il est VIDE.
     *
     * Un site est la racine de tout ce qui s'y produit. Une suppression qui
     * cascade détruirait des années d'écritures — dont la paie, qui doit être
     * conservée ; une suppression qui n'en tient pas compte laisserait des lignes
     * orphelines, rattachées à un site qui n'existe plus. Les deux sont pires que
     * de refuser.
     *
     * On ne supprime donc que ce qui n'a jamais servi : un site créé par erreur,
     * un doublon, un essai. Pour tout le reste, il y a la DÉSACTIVATION — elle
     * répond au même besoin sans rien perdre.
     */
    public function destroy(Farm $farm)
    {
        if (Gate::denies('admin.S')) return back()->with('error', 'Non autorisé.');

        $counts = $farm->dataCounts();

        if ($counts !== []) {
            $total = array_sum($counts);
            $top = collect($counts)->sortDesc()->take(3)
                ->map(fn ($n, $table) => "{$n} {$table}")->implode(', ');

            return back()->with('error',
                "« {$farm->name} » porte {$total} écriture(s) ({$top}…) : la suppression "
                . "détruirait cet historique, paie comprise. Désactivez le site : il quitte "
                . "les sélecteurs et l'historique reste consultable."
            );
        }

        if (Farm::where('id', '!=', $farm->id)->count() === 0) {
            return back()->with('error', "C'est le seul site : l'application en exige un.");
        }

        $name = $farm->name;

        // Les droits d'accès, eux, n'ont plus d'objet : ce ne sont pas des
        // écritures d'exploitation, et les laisser pointerait vers un site absent.
        DB::table('farm_user')->where('farm_id', $farm->id)->delete();

        if ((int) session('current_farm_id') === (int) $farm->id) {
            session(['current_farm_id' => Farm::active()->orderBy('id')->value('id')]);
        }

        $farm->delete();   // archivage (SoftDeletes) : traçable, et non un effacement

        return back()->with('success', "Site « {$name} » supprimé (il ne portait aucune donnée).");
    }

    /**
     * Switch vers une autre ferme.
     */
    public function switchFarm(Request $request)
    {
        $farmId = (int) $request->input('farm_id');

        $hasAccess = DB::table('farm_user')
            ->where('user_id', Auth::id())
            ->where('farm_id', $farmId)
            ->exists();

        if (! $hasAccess) {
            return back()->with('error', 'Vous n\'avez pas accès à cette ferme.');
        }

        /*
         * LE RATTACHEMENT NE SUFFISAIT PAS.
         *
         * Ce contrôle vérifiait uniquement la ligne du pivot `farm_user`. On pouvait
         * donc basculer dans un site DÉSACTIVÉ, ou même SUPPRIMÉ : la session le
         * retenait, l'en-tête le nommait, et toutes les saisies y allaient. Le
         * sélecteur de site, lui, l'excluait déjà — il faisait donc comme si ce site
         * n'existait plus, pendant qu'on pouvait continuer d'y travailler.
         *
         * Autrement dit, « désactiver un site » ne désactivait rien pour les comptes
         * qui y étaient rattachés.
         */
        if (! Farm::isUsable($farmId)) {
            return back()->with('error', __('Ce site est désactivé : impossible d’y basculer.'));
        }

        session(['current_farm_id' => $farmId]);
        $farm = Farm::find($farmId);

        return redirect()->route('dashboard')
            ->with('success', "Basculé vers : {$farm->name}");
    }

    /**
     * Gérer les accès utilisateurs d'une ferme.
     */
    public function manageUsers(Request $request, Farm $farm)
    {
        if (Gate::denies('admin.S')) return back()->with('error', 'Non autorisé.');

        $validated = $request->validate([
            'user_ids'   => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        // Sync les utilisateurs (sans toucher les propriétaires existants)
        $currentOwners = DB::table('farm_user')
            ->where('farm_id', $farm->id)
            ->where('is_owner', true)
            ->pluck('user_id')
            ->toArray();

        // Supprimer les non-propriétaires
        DB::table('farm_user')
            ->where('farm_id', $farm->id)
            ->whereNotIn('user_id', $currentOwners)
            ->delete();

        // Ajouter les nouveaux
        foreach ($validated['user_ids'] as $userId) {
            if (! in_array($userId, $currentOwners)) {
                DB::table('farm_user')->updateOrInsert(
                    ['farm_id' => $farm->id, 'user_id' => $userId],
                    ['is_default' => false, 'is_owner' => false, 'updated_at' => now()]
                );
            }
        }

        return back()->with('success', "Accès de {$farm->name} mis à jour.");
    }
}
