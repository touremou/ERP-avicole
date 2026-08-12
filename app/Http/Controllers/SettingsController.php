<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

        // Paramètres de type "image" : on transforme les fichiers uploadés
        // (et les demandes de suppression) en valeurs textuelles (chemin sur
        // le disque public) injectées dans $values, traitées comme le reste.
        foreach ((array) $request->file('settings_files', []) as $key => $file) {
            if ($file && $file->isValid()) {
                $request->validate([
                    "settings_files.$key" => 'image|mimes:png,jpg,jpeg,webp,svg|max:2048',
                ]);
                $values[$key] = $file->store('logos', 'public');
            }
        }
        foreach ((array) $request->input('settings_remove', []) as $key => $flag) {
            if ($flag) {
                $values[$key] = '';
            }
        }

        /*
         * REFUSER UNE VALEUR IMPOSSIBLE, PLUTÔT QUE DE L'ENREGISTRER.
         *
         * Cet écran n'a jamais rien validé : n'importe quelle chaîne partait en
         * base pour n'importe quel réglage. Deux conséquences, toutes deux
         * invisibles depuis le formulaire :
         *
         *   • une heure impossible (« 25:00 ») dans l'un des quatre réglages HH:MM
         *     faisait échouer `schedule:run` AVANT toute exécution, arrêtant les
         *     23 tâches planifiées — sauvegardes comprises — en silence ;
         *   • un réglage numérique renseigné en lettres est coulé en 0 par le cast
         *     (cf. Setting::castValue). Un seuil d'alerte à 0 ne ressemble pas à
         *     une panne : il alerte sur tout, ou plus sur rien, sans un mot.
         *
         * Honnêtement : le second cas est peu atteignable depuis un navigateur, qui
         * refuse déjà les lettres dans un `<input type="number">`. C'est le premier
         * qui l'est vraiment — les quatre réglages HH:MM sont rendus en TEXTE libre.
         * Le contrôle numérique reste, parce qu'un formulaire n'est pas la seule
         * façon d'atteindre cette route.
         *
         * La lecture reste défensive de son côté — un site portant déjà une valeur
         * fautive doit continuer à tourner. Mais laisser l'utilisateur enregistrer
         * une valeur qu'on remplacera ensuite dans son dos, c'est lui cacher que
         * son réglage n'a pas pris effet.
         */
        $rejected = [];

        foreach ($values as $key => $newValue) {
            $meta = Setting::where('group', $group)->where('key', $key)->whereNull('farm_id')->first();

            if (! $meta || $newValue === null || $newValue === '') {
                continue;   // vide = « ne pas toucher » / « effacer », traité plus bas
            }

            if ($meta->unit === 'HH:MM' && Setting::normalizeHour((string) $newValue) === null) {
                $rejected["settings.$key"] = __('« :value » n’est pas une heure valide pour « :label ». Format attendu HH:MM, entre 00:00 et 23:59.', [
                    'value' => $newValue, 'label' => $meta->label ?: $key,
                ]);

                continue;
            }

            if (in_array($meta->type, ['number', 'integer'], true) && ! is_numeric($newValue)) {
                $rejected["settings.$key"] = __('« :value » n’est pas un nombre pour « :label ». La valeur aurait été enregistrée comme 0.', [
                    'value' => $newValue, 'label' => $meta->label ?: $key,
                ]);
            }
        }

        if ($rejected !== []) {
            // Aucun enregistrement partiel : un groupe de réglages se lit comme un
            // tout, et n'en écrire que la moitié laisse un état que personne n'a
            // voulu.
            return back()->withInput()->withErrors($rejected)
                ->with('error', __('Aucune modification enregistrée : :n valeur(s) refusée(s).', ['n' => count($rejected)]));
        }

        $updated = 0;

        DB::transaction(function () use ($group, $values, &$updated) {
            foreach ($values as $key => $newValue) {
                $setting = Setting::where('group', $group)
                    ->where('key', $key)
                    ->whereNull('farm_id')
                    ->first();

                if (! $setting) continue;

                // Pour une image, on supprime l'ancien fichier remplacé/effacé.
                if ($setting->type === 'image'
                    && $setting->value
                    && (string) $setting->value !== (string) $newValue) {
                    Storage::disk('public')->delete($setting->value);
                }

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
