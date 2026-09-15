<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\ModulePermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\Cache;

class UserController extends Controller
{
    // ─── CLÉ DE CACHE (doit être identique à AppServiceProvider) ───
    private const CACHE_KEY = 'rbac_perms_';

    public function index()
    {
        if (Gate::denies('admin.S')) return redirect()->route('dashboard')->with('error', 'Accès réservé au Superviseur.');

        $users = User::with('userRole')->paginate((int) setting('general.items_per_page', 20));
        $roles = Role::withCount('users')->get();
        $modules = Module::active()->get();

        $moduleMatrix = [];
        foreach ($roles as $role) {
            foreach ($modules as $module) {
                $perm = ModulePermission::where('role_id', $role->id)
                    ->where('module_id', $module->id)
                    ->first();

                $moduleMatrix[$role->id][$module->id] = [
                    'L' => $perm?->can_read   ?? false,
                    'C' => $perm?->can_create ?? false,
                    'M' => $perm?->can_modify ?? false,
                    'S' => $perm?->can_delete ?? false,
                ];
            }
        }

        return view('users.index', compact('users', 'roles', 'modules', 'moduleMatrix'));
    }

    /**
     * Mise à jour de la matrice globale (rétrocompatible).
     */
    /**
     * Mise à jour de la matrice MODULE × RÔLE × LCMS.
     * Unique éditeur d'autorisation (l'ancien « LCMS global » a été retiré au
     * profit de cette matrice granulaire, désormais source de vérité unique).
     */
    public function updateModuleMatrix(Request $request)
    {
        if (Gate::denies('admin.S')) return back();

        $matrix = $request->input('module_perms', []);

        /*
         * LA PORTÉE DE L'ÉDITION EST DÉCLARÉE PAR LE FORMULAIRE, PAS DÉDUITE
         * DES CASES COCHÉES.
         *
         * Elle se déduisait de `array_keys($matrix)`. Or un navigateur n'envoie
         * PAS les cases décochées : un rôle dont on décochait tout disparaissait
         * entièrement de la charge, et échappait donc aux trois traitements à la
         * fois — pas réécrit, pas remis à zéro, cache non purgé. Ses lignes
         * restaient intactes en base.
         *
         * Mesuré : l'administrateur décoche les quatre cases de tous les modules
         * pour un compte compromis, lit « Matrice des modules mise à jour. »,
         * l'écran se rouvre avec les cases TOUJOURS COCHÉES, et le titulaire
         * conserve tous ses droits. Ce n'est pas une latence de cinq minutes :
         * tant qu'on ne laisse pas au moins une case, la révocation ne s'écrit
         * jamais. Tout décocher pour TOUS les rôles ne faisait, lui, strictement
         * rien.
         *
         * Une révocation PARTIELLE marchait, elle — c'est ce qui a rendu le
         * défaut invisible : le geste courant donne le bon résultat.
         *
         * L'écran énonce désormais les rôles qu'il gouverne (`roles_affiches`),
         * décochés compris. Un envoi sans cette portée est REFUSÉ plutôt que
         * deviné : la deviner dans un sens laisse passer la faille, la deviner
         * dans l'autre viderait la matrice d'un site sur une charge tronquée.
         */
        $portee = array_map('intval', (array) $request->input('roles_affiches', []));

        if (! $portee) {
            return back()->with('error',
                "Formulaire incomplet : la liste des rôles à enregistrer n'a pas été transmise. "
                . 'Rechargez l\'écran et recommencez — aucune modification n\'a été appliquée.'
            );
        }

        // Identifiants bornés au réel : sans cela, une charge forgée créait des
        // lignes `module_permissions` orphelines, et la remise à zéro portait sur
        // des rôles inexistants.
        $roleIds   = Role::whereIn('id', $portee)->pluck('id')->all();
        $moduleIds = Module::pluck('id');

        $matrix = array_intersect_key($matrix, array_flip($roleIds));

        return DB::transaction(function () use ($matrix, $roleIds, $moduleIds) {

            // Mettre à jour les permissions cochées
            foreach ($matrix as $roleId => $modules) {
                foreach ($modules as $moduleId => $perms) {
                    if (! $moduleIds->contains((int) $moduleId)) {
                        continue;
                    }

                    ModulePermission::updateOrCreate(
                        ['role_id' => $roleId, 'module_id' => $moduleId],
                        [
                            'can_read'   => isset($perms['L']),
                            'can_create' => isset($perms['C']),
                            'can_modify' => isset($perms['M']),
                            'can_delete' => isset($perms['S']),
                        ]
                    );
                }
            }

            foreach ($roleIds as $roleId) {
                foreach ($moduleIds as $moduleId) {
                    if (! isset($matrix[$roleId][$moduleId])) {
                        ModulePermission::where('role_id', $roleId)
                            ->where('module_id', $moduleId)
                            ->update([
                                'can_read'   => false,
                                'can_create' => false,
                                'can_modify' => false,
                                'can_delete' => false,
                            ]);
                    }
                }
            }

            // Vider le cache RBAC de tous les utilisateurs impactés
            $this->clearCacheForRoles($roleIds);

            return back()->with('success', 'Matrice des modules mise à jour.');
        });
    }

    public function store(Request $request)
    {
        if (Gate::denies('admin.S')) return back();

        // Limite d'utilisateurs du plan d'abonnement (0 / système inactif = illimité).
        $licenses = app(\App\Services\LicenseService::class);
        if (! $licenses->allowsMore('max_users', User::count())) {
            return back()->with('error', "Limite d'utilisateurs de votre abonnement atteinte ({$licenses->limit('max_users')}). Contactez le fournisseur pour l'augmenter.");
        }

        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role_id'  => ['required', 'exists:roles,id'],
        ]);

        User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role_id'  => $request->role_id,
        ]);

        return back()->with('success', "Accès créé pour {$request->name}.");
    }

    public function storeRole(Request $request)
    {
        if (Gate::denies('admin.S')) return back();

        $request->validate([
            'display_name' => 'required|string|max:255|unique:roles,display_name',
            'icon'         => 'nullable|string|max:5',
        ]);

        Role::create([
            'name'         => Str::slug($request->display_name),
            'label'        => $request->display_name,
            'display_name' => $request->display_name,
            'icon'         => $request->icon ?? '👤',
            'permissions'  => [],
        ]);

        return back()->with('success', 'Nouveau grade ajouté.');
    }

    /**
     * Ce geste ferait-il disparaître le dernier administrateur ?
     *
     * `UserController` posait déjà deux garde-fous d'auto-destruction — « on ne
     * suspend pas son propre compte », « on ne supprime pas son propre accès ».
     * Il manquait la règle dont ils dérivent : l'exploitation garde TOUJOURS au
     * moins un administrateur actif.
     *
     * Le super-administrateur est reconnu au nom de son rôle (`Gate::before`) :
     * sans lui, plus personne ne passe `admin.S`, et TOUS les chemins de
     * réparation l'exigent. L'installation ne serait plus administrable que par
     * accès SQL direct — aucune commande de secours n'existe.
     *
     * @return string|null Le motif du refus, ou null si le geste est permis.
     */
    private function refusSiDernierAdministrateur(User $cible): ?string
    {
        if (! $cible->administersTheInstallation()) {
            return null;   // retirer un non-administrateur ne coûte rien
        }

        if (User::administrators()->whereKeyNot($cible->id)->exists()) {
            return null;   // il en reste au moins un autre
        }

        return "{$cible->name} est le dernier administrateur actif : lui retirer ce rôle "
            . "rendrait l'installation inadministrable. Nommez d'abord un autre administrateur.";
    }

    public function updateRole(Request $request, User $user)
    {
        if (Gate::denies('admin.S')) return back();

        /*
         * ON NE SE RÉTROGRADE PAS SOI-MÊME.
         *
         * Même garde-fou que pour la suspension et la suppression, et c'est ici
         * le geste le PLUS exposé : la liste des comptes porte un menu déroulant
         * de rôle sur chaque ligne, y compris la sienne, qui s'envoie au
         * `change`. Une fausse manœuvre au clavier suffisait.
         */
        if (auth()->id() === $user->id) {
            return back()->with('error',
                'Impossible de changer votre propre rôle. Demandez-le à un autre administrateur.'
            );
        }

        $validated = $request->validate(['role_id' => 'required|exists:roles,id']);

        if ($validated['role_id'] != $user->role_id
            && $motif = $this->refusSiDernierAdministrateur($user)) {
            return back()->with('error', $motif);
        }

        $user->update(['role_id' => $validated['role_id']]);

        // Vider le cache de CET utilisateur
        Cache::forget(self::CACHE_KEY . $user->id);

        return back()->with('success', "Rôle de {$user->name} mis à jour.");
    }

    /** Édition d'un utilisateur (nom, email, rôle) — réservé admin. */
    public function update(Request $request, User $user)
    {
        if (Gate::denies('admin.S')) return back();

        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        // Cette porte écrit `role_id` dans la même passe que le nom et l'e-mail :
        // sans la même garde, la porte de devant serait fermée et celle-ci ouverte.
        if ($validated['role_id'] != $user->role_id
            && $motif = $this->refusSiDernierAdministrateur($user)) {
            return back()->with('error', $motif);
        }

        $user->update($validated);
        Cache::forget(self::CACHE_KEY . $user->id); // le rôle a pu changer

        return back()->with('success', "Utilisateur {$user->name} mis à jour.");
    }

    /** Suspend / réactive un compte (bloque/rouvre la connexion via is_active). */
    public function toggleActive(User $user)
    {
        if (Gate::denies('admin.S')) return back();

        if (auth()->id() === $user->id) {
            return back()->with('error', 'Impossible de suspendre votre propre compte.');
        }

        // Un autre compte porteur d'`admin.S` PAR LA MATRICE pouvait suspendre le
        // dernier vrai administrateur — et se retrouver sans personne pour rouvrir.
        if ($user->isActive() && $motif = $this->refusSiDernierAdministrateur($user)) {
            return back()->with('error', $motif);
        }

        $suspension = $user->isActive();   // l'état AVANT bascule

        $user->update(['is_active' => ! $user->isActive()]);
        Cache::forget(self::CACHE_KEY . $user->id);

        /*
         * SUSPENDRE COUPE LES APPAREILS DÉJÀ APPAIRÉS.
         *
         * `is_active` ne bloquait que les connexions FUTURES : le jeton Sanctum
         * déjà émis continuait de lire les référentiels et d'écrire par la file
         * de synchronisation. Le téléphone d'un agent licencié restait donc
         * pleinement opérationnel après la suspension.
         *
         * On ne rend rien à la réactivation : un jeton révoqué l'est pour de
         * bon, et l'appareil se ré-appaire. C'est le propre d'une révocation.
         */
        if ($suspension) {
            $user->tokens()->delete();
        }

        return back()->with('success', $user->is_active
            ? "Accès de {$user->name} réactivé."
            : "Accès de {$user->name} suspendu.");
    }

    /** Réinitialise le mot de passe d'un utilisateur (admin). */
    public function resetPassword(Request $request, User $user)
    {
        if (Gate::denies('admin.S')) return back();

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user->update(['password' => Hash::make($validated['password'])]);

        /*
         * Un jeton ne dépend PAS du mot de passe : sans cette ligne, changer le
         * mot de passe d'un compte compromis laissait l'appareil de l'intrus
         * connecté et écrivant. C'est pourtant la raison même pour laquelle on
         * réinitialise.
         */
        $user->tokens()->delete();

        return back()->with('success',
            "Mot de passe de {$user->name} réinitialisé. Ses appareils devront se reconnecter."
        );
    }

    public function destroy(User $user)
    {
        if (Gate::denies('admin.S')) return back();

        if (auth()->id() === $user->id) {
            return back()->with('error', 'Impossible de supprimer votre propre accès.');
        }

        if ($motif = $this->refusSiDernierAdministrateur($user)) {
            return back()->with('error', $motif);
        }

        // Vider le cache avant suppression
        Cache::forget(self::CACHE_KEY . $user->id);

        // Révoquer un accès doit valoir au moins autant que le suspendre : les
        // jetons ne sont pas emportés par la suppression du modèle.
        $user->tokens()->delete();

        $user->delete();
        return back()->with('success', 'Utilisateur révoqué.');
    }

    public function destroyRole(Role $role)
    {
        if (Gate::denies('admin.S')) return back();

        if ($role->users()->count() > 0) {
            return back()->with('error', 'Action refusée : ce rôle est assigné à des utilisateurs.');
        }

        // Vider le cache de tous les utilisateurs de ce rôle (précaution)
        $this->clearCacheForRoles([$role->id]);

        ModulePermission::where('role_id', $role->id)->delete();
        $role->delete();

        return back()->with('success', 'Le rôle a été supprimé.');
    }

    // ══════════════════════════════════════════════════════════════
    // HELPER PRIVÉ
    // ══════════════════════════════════════════════════════════════

    /**
     * Vide le cache RBAC pour tous les utilisateurs des rôles donnés.
     */
    private function clearCacheForRoles(array $roleIds): void
    {
        User::whereIn('role_id', $roleIds)
            ->pluck('id')
            ->each(fn($uid) => Cache::forget(self::CACHE_KEY . $uid));
    }

    public function updatePermissions(Request $request, Role $role)
    {
        foreach ($request->input('modules', []) as $moduleId => $perms) {
            \App\Models\ModulePermission::updateOrCreate(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                [
                    'can_read'   => !empty($perms['L']),
                    'can_create' => !empty($perms['C']),
                    'can_modify' => !empty($perms['M']),
                    'can_delete' => !empty($perms['S']),
                ]
            );
        }

        // Vider le cache pour tous les utilisateurs de ce rôle. La condition
        // portait aussi sur `users.role`, colonne dépréciée qui contient un NOM
        // de rôle, comparé ici à un IDENTIFIANT : elle ne pouvait rien apparier.
        // Seul `role_id` fait foi (cf. AppServiceProvider).
        $this->clearCacheForRoles([$role->id]);

        return back()->with('success', 'Permissions mises à jour.');
    }
}
