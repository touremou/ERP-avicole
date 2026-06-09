<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class SetupController extends Controller
{
    // Affiche la page uniquement si aucun utilisateur n'existe
    public function index()
    {
        if (User::count() > 0) {
            return redirect()->route('login');
        }

        return view('setup.index');
    }

    // Traite la création du Super Admin
    public function store(Request $request)
    {
        // VERROU DE SÉCURITÉ ABSOLU
        if (User::count() > 0) {
            abort(403, 'L\'application est déjà initialisée.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // 1. Création du rôle Super Admin (s'il n'existe pas via les Seeders)
        $superAdminRole = Role::firstOrCreate(
            ['display_name' => 'Super Admin'],
            [
                'icon' => '👑',
                'permissions' => ['L', 'C', 'M', 'S'] // Tous les droits
            ]
        );

        // 2. Création de l'utilisateur
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => $superAdminRole->id,
        ]);

        // 3. Authentification immédiate
        Auth::login($user);

        return redirect()->route('dashboard')->with('success', 'Bienvenue ! Votre ERP AviSmart est maintenant initialisé.');
    }
}