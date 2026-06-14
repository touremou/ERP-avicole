<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Extensions\OfflineUserProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * AppServiceProvider — AviSmart ERP
 *
 * Gates HYBRIDES : Rôle global + Fallback permissions modules
 *
 * Schéma vérifié :
 *   module_permissions : id, role_id (FK), module_id (FK),
 *                        can_read, can_create, can_modify, can_delete
 *   modules            : id, slug, name, icon, color, is_active
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Auth::provider('offline_eloquent', function ($app, array $config) {
            return new OfflineUserProvider($app['hash'], $config['model']);
        });
    }

    public function boot(): void
    {
        // ─── 1. DÉTECTION PANNE MySQL ───
        config(['app.database_down' => false]);

        if (! app()->runningInConsole()) {
            try {
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                config(['app.database_down' => true]);
                Log::warning('AppServiceProvider: MySQL inaccessible — mode offline activé.');
            }
        }

        // ─── 1b. FUSEAU HORAIRE PILOTÉ PAR LES PARAMÈTRES ───
        // Le réglage general.timezone (Paramètres > Général) était éditable mais
        // jamais appliqué. On l'applique ici s'il est valide (sinon on ignore).
        //
        // Schema::hasTable() lui-même peut lever (ex : fichier SQLite encore
        // inexistant sur une toute première installation), donc il doit être
        // dans le try/catch, pas seulement la lecture du paramètre.
        try {
            if (! config('app.database_down') && Schema::hasTable('settings')) {
                $tz = setting('general.timezone');
                if ($tz && in_array($tz, timezone_identifiers_list(), true)) {
                    config(['app.timezone' => $tz]);
                    date_default_timezone_set($tz);
                }
            }
        } catch (\Throwable $e) {
            // Base de données ou paramètres indisponibles (installation en cours, etc.) : on garde la valeur par défaut.
        }

        // ─── 2. OBSERVERS ───
        if (! config('app.database_down')) {
            \App\Models\Batch::observe(\App\Observers\BatchObserver::class);
        }

        // ─── 3. FIX SQL STRING LENGTH ───
        Schema::defaultStringLength(191);

        // ─── 4. BREADCRUMBS AUTO ───
        View::composer('*', function ($view) {
            $segments = Request::segments();
            $breadcrumbs = [];
            $url = '';
            foreach ($segments as $segment) {
                $url .= '/' . $segment;
                if (! is_numeric($segment)) {
                    $breadcrumbs[] = [
                        'label' => Str::title(str_replace(['-', '_'], ' ', $segment)),
                        'url'   => url($url),
                    ];
                }
            }
            $view->with('autoBreadcrumbs', $breadcrumbs);
        });

        // ════════════════════════════════════════════════════════════════
        // 5. GATES HYBRIDES (GLOBAL + MODULES)
        //    Corrigé pour le schéma réel :
        //    - role_id (FK int), PAS role (string)
        //    - can_modify, PAS can_update
        //    - Pas de colonne is_active sur module_permissions
        // ════════════════════════════════════════════════════════════════

        // Correspondance "préfixe de nom de route" → slug module.
        // SOURCE DE VÉRITÉ UNIQUE : App\Models\Module::routePrefixMap().
        // Permet aux gates génériques L/C/M/S (utilisés par
        // ->middleware('can:L'|'can:C'|'can:M'|'can:S') dans routes/web.php)
        // de résoudre le module concerné par la requête en cours, et donc
        // d'appliquer la matrice Modules × Rôles route par route.
        $moduleRouteMap = \App\Models\Module::routePrefixMap();

        $resolveModuleSlug = function () use ($moduleRouteMap) {
            $name = request()->route()?->getName();
            if (! $name) return null;

            foreach ($moduleRouteMap as $prefix => $slug) {
                if (str_starts_with($name, $prefix)) return $slug;
            }

            return null;
        };

        /**
         * Helper : charge les permissions modules via role_id.
         * Cache 5 minutes. Vider avec : Cache::forget("rbac_perms_{$userId}")
         */
        $getModulePerms = function (int $userId) {
            return Cache::remember("rbac_perms_{$userId}", 300, function () use ($userId) {
                try {
                    if (! Schema::hasTable('module_permissions')) return [];

                    $user = User::find($userId);
                    if (! $user) return [];

                    // ✅ Utilise role_id (FK), pas role string
                    $roleId = $user->userRole?->id ?? null;
                    if (! $roleId) return [];

                    return DB::table('module_permissions')
                        ->join('modules', 'modules.id', '=', 'module_permissions.module_id')
                        ->where('module_permissions.role_id', $roleId) // ✅ role_id
                        // Pas de filtre is_active (colonne absente)       ✅
                        ->select(
                            'modules.slug',
                            'module_permissions.can_read',
                            'module_permissions.can_create',
                            'module_permissions.can_modify',  // ✅ can_modify, pas can_update
                            'module_permissions.can_delete'
                        )
                        ->get()
                        ->mapWithKeys(fn($r) => [$r->slug => [
                            'L' => (bool) $r->can_read,
                            'C' => (bool) $r->can_create,
                            'M' => (bool) $r->can_modify,    // ✅
                            'S' => (bool) $r->can_delete,
                        ]])
                        ->toArray();
                } catch (\Throwable $e) {
                    Log::debug("rbac_perms error user {$userId}: " . $e->getMessage());
                    return [];
                }
            });
        };

        // ─── A. ADMIN BYPASS (doit être défini EN PREMIER) ───
        Gate::before(function (?User $user, string $ability) {
            if (! $user || config('app.database_down')) return null;

            try {
                if (($user->userRole?->name ?? '') === 'admin') return true;
            } catch (\Exception) {
                return null;
            }

            return null;
        });

        // ─── B. GATES GLOBAUX (L, C, M, S) ───
        // La matrice Modules × Rôles (`module_permissions`) est la SEULE
        // source de vérité (chaque rôle possède une ligne par module — cf.
        // migrations 2026_06_10_000004 et 2026_06_14_000001). Plus aucun
        // fallback sur un rôle global : un module non coché = aucun accès.
        foreach (['L', 'C', 'M', 'S'] as $perm) {
            Gate::define($perm, function (?User $user) use ($perm, $getModulePerms, $resolveModuleSlug) {
                if (! $user) return false;

                // Mode offline : L et C uniquement
                if (config('app.database_down')) return in_array($perm, ['L', 'C']);

                $modulePerms = $getModulePerms($user->id);
                $slug = $resolveModuleSlug();

                if ($slug !== null) {
                    // Module identifié : la matrice décide seule.
                    return ! empty($modulePerms[$slug][$perm]);
                }

                // Route non rattachée à un module précis (ex: dashboard) :
                // accès si au moins un module accorde cette permission.
                foreach ($modulePerms as $perms) {
                    if (! empty($perms[$perm])) return true;
                }

                return false;
            });
        }

        // ─── C. GATES PAR MODULE (elevage.L, commerce.C, annuaire.M, ...) ───
        // Slugs réels de la base (13 modules actifs). Ce tableau ne sert que
        // de repli si la table `modules` est indisponible (installation/offline).
        $fallbackSlugs = [
            'dashboard', 'elevage', 'production', 'provenderie', 'planning',
            'abattoir', 'commerce', 'logistique', 'ressources', 'notifications',
            'annuaire', 'admin', 'depenses',
        ];

        try {
            $slugs = Schema::hasTable('modules')
                ? \App\Models\Module::where('is_active', true)->pluck('slug')->toArray()
                : $fallbackSlugs;

            if (empty($slugs)) $slugs = $fallbackSlugs;
        } catch (\Throwable) {
            $slugs = $fallbackSlugs;
        }

        foreach ($slugs as $slug) {
            foreach (['L', 'C', 'M', 'S'] as $perm) {
                Gate::define("{$slug}.{$perm}", function (?User $user) use ($slug, $perm, $getModulePerms) {
                    if (! $user) return false;
                    if (config('app.database_down')) return in_array($perm, ['L', 'C']);

                    // Admin bypass (géré par Gate::before, mais sécurité double)
                    if (($user->userRole?->name ?? '') === 'admin') return true;

                    $modulePerms = $getModulePerms($user->id);

                    return ! empty($modulePerms[$slug][$perm]);
                });
            }
        }
    }
}