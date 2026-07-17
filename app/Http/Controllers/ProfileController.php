<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Affiche le formulaire de modification du profil.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Met à jour les informations du profil utilisateur.
     * Rigueur : Gère automatiquement la réinitialisation de la vérification email.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Photo de profil — MÊME champ (users.avatar_path) que le mobile : la photo
     * est donc partagée entre le web et l'app terrain (une seule à charger).
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'image', 'max:5120']]);

        $user = $request->user();
        $old = $user->avatar_path;

        $path = $request->file('photo')->store('avatars', 'public');
        $user->update(['avatar_path' => $path]);

        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return Redirect::route('profile.edit')->with('status', 'avatar-updated');
    }

    /** Retrait de la photo de profil (retour aux initiales, web + mobile). */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return Redirect::route('profile.edit')->with('status', 'avatar-removed');
    }

    /**
     * Suppression du compte utilisateur.
     * Sécurité : Nécessite la confirmation du mot de passe actuel.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Rigueur ERP : On déconnecte avant de supprimer pour invalider la session immédiatement
        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}