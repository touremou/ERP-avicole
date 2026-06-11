<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('admin.S')) return redirect()->route('dashboard')->with('error', 'Accès réservé aux administrateurs.');

        $activeGroup = $request->input('group', 'general');
        $groups = Setting::getGroups();

        $settings = Setting::whereNull('farm_id')
            ->where('group', $activeGroup)
            ->orderBy('display_order')
            ->get();

        // Onglet Général : aperçu en direct des espèces actives sur ce site
        // (source de vérité = table species, gérée depuis /admin/species).
        $activeSpecies = $activeGroup === 'general'
            ? Species::where('is_active', true)->orderBy('sort_order')->get(['name_fr', 'family'])
            : null;

        return view('settings.index', compact('groups', 'activeGroup', 'settings', 'activeSpecies'));
    }

    public function logs(Request $request)
    {
        if (Gate::denies('admin.S')) return redirect()->route('settings.index')->with('error', 'Accès réservé.');

        $query = DB::table('setting_audits')
            ->join('users', 'users.id', '=', 'setting_audits.user_id')
            ->select('setting_audits.*', 'users.name as user_name');

        // Filtres
        if ($request->filled('group')) {
            $query->where('setting_audits.group', $request->input('group'));
        }
        if ($request->filled('user')) {
            $query->where('setting_audits.user_id', $request->input('user'));
        }
        if ($request->filled('from')) {
            $query->where('setting_audits.created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('setting_audits.created_at', '<=', $request->input('to') . ' 23:59:59');
        }

        $audits = $query->latest('setting_audits.created_at')->paginate((int) setting('general.items_per_page', 20));

        $groups = Setting::getGroups();
        $users = DB::table('setting_audits')
            ->join('users', 'users.id', '=', 'setting_audits.user_id')
            ->select('users.id', 'users.name')
            ->distinct()
            ->get();

        return view('settings.logs', compact('audits', 'groups', 'users'));
    }

    public function update(Request $request)
    {
        if (Gate::denies('admin.S')) return back()->with('error', 'Non autorisé.');

        $group = $request->input('_group');
        $values = $request->input('settings', []);

        $updated = 0;

        DB::transaction(function () use ($group, $values, &$updated) {
            foreach ($values as $key => $newValue) {
                $setting = Setting::where('group', $group)
                    ->where('key', $key)
                    ->whereNull('farm_id')
                    ->first();

                if (! $setting) continue;

                $oldValue = $setting->value;

                // Ne pas écraser les champs sensibles vides (l'admin n'a pas rempli = garder l'ancien)
                if ($setting->is_sensitive && ($newValue === null || $newValue === '')) {
                    continue;
                }

                if ((string) $oldValue !== (string) $newValue) {
                    $setting->update(['value' => $newValue]);
                    $updated++;

                    // Audit trail
                    DB::table('setting_audits')->insert([
                        'group'      => $group,
                        'key'        => $key,
                        'old_value'  => $setting->is_sensitive ? '***' : $oldValue,
                        'new_value'  => $setting->is_sensitive ? '***' : $newValue,
                        'user_id'    => Auth::id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        // Vider le cache
        Setting::clearCache();

        if ($updated > 0) {
            Log::info("Paramètres [{$group}] : {$updated} valeur(s) modifiée(s) par " . Auth::user()->name);
        }

        return back()->with('success', $updated > 0
            ? "{$updated} paramètre(s) mis à jour."
            : "Aucune modification détectée.");
    }
}
