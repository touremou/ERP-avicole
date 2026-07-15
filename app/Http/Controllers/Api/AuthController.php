<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Authentification API (Sanctum, tokens personnels) — destinée à
 * l'application mobile. Chaque appareil obtient son propre token
 * (révocable individuellement — cf. DeviceController).
 *
 * `me` renvoie tout ce dont la home par rôle et le gate hors-ligne ont
 * besoin (permissions Modules × L/C/M/S filtrées par la licence, périmètre
 * fermes) : le client le met en cache et l'UI s'en sert sans réseau.
 * Le serveur re-vérifie TOUJOURS les Gates au push (défense en profondeur).
 * Cf. docs/mobile/phase-0-spec.md §3.2.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'required|string|max:100',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('Identifiants invalides.')],
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => [__('Ce compte est désactivé.')],
            ]);
        }

        return response()->json([
            'token' => $user->createToken($credentials['device_name'])->plainTextToken,
            'user' => $this->userPayload($user),
            // Le serveur fait foi pour le temps : le client ne se fie jamais
            // à l'horloge du téléphone pour son `since` de sync.
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('userRole');

        return response()->json([
            'user'        => $this->userPayload($user),
            'role'        => [
                'slug'  => $user->userRole?->name,
                'label' => $user->userRole?->display_name ?? $user->userRole?->label ?? $user->userRole?->name,
            ],
            'permissions' => $this->permissionsMatrix($user),
            'scope'       => $this->scope($user),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('Déconnecté.')]);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('userRole');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->userRole?->name,
            // Langue du profil web : la PWA l'adopte (sauf choix manuel local).
            'locale' => $user->locale,
        ];
    }

    /**
     * Matrice Modules × L/C/M/S au format compact du contrat API :
     *   { "elevage": ["L","C","M"], "commerce": ["L"], ... }
     *
     * Base : getAccessibleModules() (modules actifs, au moins lecture,
     * DÉJÀ filtrés par le verrou de licence) — un module hors abonnement
     * n'apparaît jamais, même pour un admin.
     */
    private function permissionsMatrix(User $user): array
    {
        $matrix = [];

        foreach ($user->getAccessibleModules() as $module) {
            $levels = array_values(array_filter(
                ['L', 'C', 'M', 'S'],
                fn (string $level) => $user->canModule($module->slug, $level)
            ));

            if ($levels !== []) {
                $matrix[$module->slug] = $levels;
            }
        }

        return $matrix;
    }

    /**
     * Périmètre de l'utilisateur : ferme courante (fixée par SetApiFarmContext,
     * donc cohérente avec ce que pull/push verront) + liste de ses fermes
     * (pivot farm_user) pour le sélecteur multi-sites du client.
     */
    private function scope(User $user): array
    {
        $farms = DB::table('farm_user')
            ->join('farms', 'farms.id', '=', 'farm_user.farm_id')
            ->where('farm_user.user_id', $user->id)
            ->orderBy('farms.name')
            ->get(['farms.id', 'farms.name', 'farm_user.is_default'])
            ->map(fn ($f) => [
                'id'         => (int) $f->id,
                'name'       => $f->name,
                'is_default' => (bool) $f->is_default,
            ])
            ->values();

        return [
            'farm_id' => session('current_farm_id') ? (int) session('current_farm_id') : null,
            'farms'   => $farms,
        ];
    }
}
