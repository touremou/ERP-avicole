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

        $farms = Farm::withoutGlobalScopes()->withCount('users')->get();
        $users = User::orderBy('name')->get();

        return view('farms.index', compact('farms', 'users'));
    }

    public function store(Request $request)
    {
        if (Gate::denies('admin.S')) return back()->with('error', 'Non autorisé.');

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

        session(['current_farm_id' => $farmId]);
        $farm = Farm::withoutGlobalScopes()->find($farmId);

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
