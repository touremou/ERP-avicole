<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\ModulePermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

class UserController extends Controller
{
    public function index()
    {
        if (Gate::denies('S')) return redirect()->route('dashboard')->with('error', 'Accès réservé au Superviseur.');

        $users = User::with('userRole.permissions')->paginate(20);
        $roles = Role::with('permissions')->withCount('users')->get();
        $allPermissions = Permission::all();
        $modules = Module::active()->get();

        // Matrice module × rôle
        $moduleMatrix = [];
        foreach ($roles as $role) {
            foreach ($modules as $module) {
                $perm = ModulePermission::where('role_id', $role->id)
                    ->where('module_id', $module->id)
                    ->first();

                $moduleMatrix[$role->id][$module->id] = [
                    'L' => $perm?->can_read ?? false,
                    'C' => $perm?->can_create ?? false,
                    'M' => $perm?->can_modify ?? false,
                    'S' => $perm?->can_delete ?? false,
                ];
            }
        }

        return view('users.index', compact('users', 'roles', 'allPermissions', 'modules', 'moduleMatrix'));
    }

    /**
     * Mise à jour de la matrice globale (rétrocompatible).
     */
    public function updateMatrix(Request $request)
    {
        if (Gate::denies('S')) return back();

        $matrix = $request->input('permissions', []);

        return DB::transaction(function () use ($matrix) {
            foreach (Role::all() as $role) {
                $permissionNames = $matrix[$role->id] ?? [];
                $permissionIds = Permission::whereIn('name', $permissionNames)->pluck('id');
                $role->permissions()->sync($permissionIds);
            }
            return back()->with('success', 'Matrice globale synchronisée.');
        });
    }

    /**
     * Mise à jour de la matrice MODULE × RÔLE × LCMS.
     */
    public function updateModuleMatrix(Request $request)
    {
        
        if (Gate::denies('S')) return back();

        $matrix = $request->input('module_perms', []);
        

        return DB::transaction(function () use ($matrix) {
            // Format reçu : module_perms[role_id][module_id][L|C|M|S] = "1"
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

            // Nettoyer les modules non cochés (aucune permission)
            $roleIds = array_keys($matrix);
            $moduleIds = Module::pluck('id');

            foreach ($roleIds as $roleId) {
                foreach ($moduleIds as $moduleId) {
                    if (! isset($matrix[$roleId][$moduleId])) {
                        ModulePermission::where('role_id', $roleId)
                            ->where('module_id', $moduleId)
                            ->update([
                                'can_read' => false, 'can_create' => false,
                                'can_modify' => false, 'can_delete' => false,
                            ]);
                    }
                }
            }

            return back()->with('success', 'Matrice des modules mise à jour.');
        });
    }

    public function store(Request $request)
    {
        if (Gate::denies('S')) return back();

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
        if (Gate::denies('S')) return back();

        $request->validate([
            'display_name' => 'required|string|max:255|unique:roles,display_name',
            'icon'         => 'nullable|string|max:5',
        ]);

        Role::create([
            'name'         => Str::slug($request->display_name),
            'display_name' => $request->display_name,
            'icon'         => $request->icon ?? '👤',
        ]);

        return back()->with('success', 'Nouveau grade ajouté.');
    }

    public function updateRole(Request $request, User $user)
    {
        if (Gate::denies('S')) return back();

        $validated = $request->validate(['role_id' => 'required|exists:roles,id']);
        $user->update(['role_id' => $validated['role_id']]);

        return back()->with('success', "Rôle de {$user->name} mis à jour.");
    }

    public function destroy(User $user)
    {
        if (Gate::denies('S')) return back();

        if (auth()->id() === $user->id) {
            return back()->with('error', 'Impossible de supprimer votre propre accès.');
        }

        $user->delete();
        return back()->with('success', 'Utilisateur révoqué.');
    }

    /**
     * Supprimer un rôle non utilisé.
     */
    public function destroyRole(Role $role)
    {
        // 1. Vérification des droits (Superviseur uniquement)
        if (Gate::denies('S')) return back();

        // 2. Sécurité absolue : on empêche la suppression si le rôle est utilisé
        if ($role->users()->count() > 0) {
            return back()->with('error', 'Action refusée : Ce rôle est actuellement assigné à des utilisateurs.');
        }

        // 3. Nettoyage des permissions associées (Optionnel mais recommandé si vous utilisez des tables pivots)
        $role->permissions()->detach();
        
        // Nettoyage dans ModulePermission (si applicable)
        ModulePermission::where('role_id', $role->id)->delete();

        // 4. Suppression définitive
        $role->delete();

        return back()->with('success', 'Le rôle a été définitivement supprimé.');
    }
}
