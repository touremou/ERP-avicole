<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * EmployeeAccessController — Espace employé : gestion du compte de connexion
 * (User) rattaché à une fiche RH (Employee).
 *
 * Action sensible (création d'identité + droits) → réservée à `admin.S`.
 * Les permissions effectives restent gérées par le rôle (gates RBAC).
 */
class EmployeeAccessController extends Controller
{
    /**
     * Crée un compte de connexion pour un employé et le lui rattache.
     * Le mot de passe temporaire est affiché une seule fois (flash).
     */
    public function store(Request $request, Employee $employee)
    {
        if (Gate::denies('admin.S')) {
            return back()->with('error', "Action réservée aux administrateurs.");
        }

        if ($employee->user_id) {
            return back()->with('error', "Cet employé possède déjà un accès.");
        }

        $validated = $request->validate([
            'email'   => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
        ], [
            'email.unique' => "Cet email est déjà utilisé par un autre compte.",
            'role_id.required' => "Le rôle est obligatoire.",
        ]);

        // Mot de passe temporaire lisible (lettres + chiffres, sans symboles).
        $tempPassword = Str::password(10, true, true, false);

        $user = User::create([
            'name'      => trim($employee->first_name . ' ' . $employee->last_name),
            'email'     => $validated['email'],
            'password'  => Hash::make($tempPassword),
            'role_id'   => $validated['role_id'],
            'is_active' => true,
        ]);

        $employee->update(['user_id' => $user->id]);

        Log::info("Accès créé pour l'employé {$employee->employee_id} → user #{$user->id} ({$user->email}).");

        return back()
            ->with('success', "Accès créé pour {$user->name}.")
            ->with('temp_password', $tempPassword)
            ->with('temp_email', $user->email);
    }

    /**
     * Met à jour l'accès : changement de rôle ou activation/désactivation.
     */
    public function update(Request $request, Employee $employee)
    {
        if (Gate::denies('admin.S')) {
            return back()->with('error', "Action réservée aux administrateurs.");
        }

        $user = $employee->user;
        if (! $user) {
            return back()->with('error', "Cet employé n'a pas de compte.");
        }

        $validated = $request->validate([
            'action'  => ['required', Rule::in(['role', 'activate', 'deactivate'])],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
        ]);

        switch ($validated['action']) {
            case 'role':
                if (empty($validated['role_id'])) {
                    return back()->with('error', "Sélectionnez un rôle.");
                }
                $user->update(['role_id' => $validated['role_id']]);
                $message = "Rôle mis à jour pour {$user->name}.";
                break;

            case 'deactivate':
                $user->update(['is_active' => false]);
                $message = "Accès désactivé pour {$user->name}.";
                break;

            case 'activate':
            default:
                $user->update(['is_active' => true]);
                $message = "Accès réactivé pour {$user->name}.";
                break;
        }

        // La matrice de permissions est mise en cache par utilisateur.
        Cache::forget("rbac_perms_{$user->id}");

        return back()->with('success', $message);
    }

    /**
     * Réinitialise le mot de passe (affiché une seule fois).
     */
    public function resetPassword(Employee $employee)
    {
        if (Gate::denies('admin.S')) {
            return back()->with('error', "Action réservée aux administrateurs.");
        }

        $user = $employee->user;
        if (! $user) {
            return back()->with('error', "Cet employé n'a pas de compte.");
        }

        $tempPassword = Str::password(10, true, true, false);
        $user->update(['password' => Hash::make($tempPassword)]);

        Log::info("Mot de passe réinitialisé pour user #{$user->id} ({$user->email}).");

        return back()
            ->with('success', "Mot de passe réinitialisé pour {$user->name}.")
            ->with('temp_password', $tempPassword)
            ->with('temp_email', $user->email);
    }
}
