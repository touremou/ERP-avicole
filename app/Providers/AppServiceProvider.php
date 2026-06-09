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

        $globalRoleMap = [
            'L' => ['admin', 'manager', 'operator', 'viewer'],
            'C' => ['admin', 'manager', 'operator'],
            'M' => ['admin', 'manager'],
            'S' => ['admin'],
        ];

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
        foreach (['L', 'C', 'M', 'S'] as $perm) {
            Gate::define($perm, function (?User $user) use ($perm, $globalRoleMap, $getModulePerms) {
                if (! $user) return false;

                // Mode offline : L et C uniquement
                if (config('app.database_down')) return in_array($perm, ['L', 'C']);

                $roleName = $user->userRole?->name ?? '';

                // Rôle global autorise ?
                if (in_array($roleName, $globalRoleMap[$perm])) return true;

                // Fallback : au moins 1 module avec cette permission
                foreach ($getModulePerms($user->id) as $modulePerms) {
                    if (! empty($modulePerms[$perm])) return true;
                }

                return false;
            });
        }

        // ─── C. GATES PAR MODULE (elevage.L, commerce.C, rh.M, ...) ───
        // Slugs réels de la base (vérifiés via Tinker)
        $fallbackSlugs = [
            'dashboard', 'elevage', 'production', 'provenderie', 'planning',
            'abattoir', 'commerce', 'logistique', 'ressources', 'notifications',
            'annuaire', 'admin', 'rh', 'couvoir', 'stocks',
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
                Gate::define("{$slug}.{$perm}", function (?User $user) use ($slug, $perm, $globalRoleMap, $getModulePerms) {
                    if (! $user) return false;
                    if (config('app.database_down')) return in_array($perm, ['L', 'C']);

                    $roleName = $user->userRole?->name ?? '';

                    // Admin bypass (géré par Gate::before, mais sécurité double)
                    if ($roleName === 'admin') return true;

                    // Rôle global = accès universel sur ce niveau
                    if (in_array($roleName, $globalRoleMap[$perm])) return true;

                    // Permission spécifique au module
                    return ! empty($getModulePerms($user->id)[$slug][$perm]);
                });
            }
        }
    }
}