<?php

namespace App\Http\Controllers;

use App\Models\Setting;
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

        // Dernières modifications
        $recentAudits = DB::table('setting_audits')
            ->join('users', 'users.id', '=', 'setting_audits.user_id')
            ->select('setting_audits.*', 'users.name as user_name')
            ->latest('setting_audits.created_at')
            ->limit(10)
            ->get();

        return view('settings.index', compact('groups', 'activeGroup', 'settings', 'recentAudits'));
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
