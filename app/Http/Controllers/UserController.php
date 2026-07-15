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

        return DB::transaction(function () use ($matrix) {

            // Mettre à jour les permissions cochées
            foreach ($matrix as $roleId => $modules) {
                foreach ($modules as $moduleId => $perms) {
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

            // Remettre à zéro les modules non cochés
            $roleIds   = array_keys($matrix);
            $moduleIds = Module::pluck('id');

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

    public function updateRole(Request $request, User $user)
    {
        if (Gate::denies('admin.S')) return back();

        $validated = $request->validate(['role_id' => 'required|exists:roles,id']);
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

        $user->update(['is_active' => ! $user->isActive()]);
        Cache::forget(self::CACHE_KEY . $user->id);

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

        return back()->with('success', "Mot de passe de {$user->name} réinitialisé.");
    }

    public function destroy(User $user)
    {
        if (Gate::denies('admin.S')) return back();

        if (auth()->id() === $user->id) {
            return back()->with('error', 'Impossible de supprimer votre propre accès.');
        }

        // Vider le cache avant suppression
        Cache::forget(self::CACHE_KEY . $user->id);

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

        // Vider le cache pour tous les utilisateurs de ce rôle
        $userIds = \App\Models\User::where('role', $role->id)
            ->orWhere('role_id', $role->id)
            ->pluck('id');
        foreach ($userIds as $uid) {
            Cache::forget("rbac_perms_{$uid}");
        }

        return back()->with('success', 'Permissions mises à jour.');
    }
}
