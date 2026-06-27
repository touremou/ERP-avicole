<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role_id', 'whatsapp_phone', 'is_active', 'locale',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    // ─── RELATIONS ───

    public function userRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Fiche employé (RH) rattachée à ce compte de connexion, le cas échéant.
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function dashboardConfiguration(): HasOne
    {
        return $this->hasOne(DashboardConfiguration::class);
    }

    // ─── RBAC GLOBAL (rétrocompatible) ───

    /**
     * Vérifie une permission globale (L, C, M, S).
     * Utilisé par Gate::define('L'), etc.
     */
    public function hasPermission(string $permissionName): bool
    {
        if (! $this->role_id) return false;

        $this->loadMissing('userRole');

        if (! $this->userRole) return false;

        return $this->userRole->hasPermission($permissionName);
    }

    // ─── RBAC PAR MODULE ───

    /**
     * Vérifie si l'utilisateur a une permission spécifique sur un module.
     *
     * Usage :
     *   $user->canModule('elevage', 'C')    // Peut créer dans le module Élevage ?
     *   $user->canModule('abattoir', 'L')   // Peut lire le module Abattoir ?
     *   $user->canModule('admin', 'S')      // Peut supprimer dans Administration ?
     *
     * La matrice Modules × Rôles (`module_permissions`) est seule autorité :
     * chaque rôle possède une ligne par module (cf. migrations
     * 2026_06_10_000004 et 2026_06_14_000001). Le rôle "admin" reste
     * bypassé partout via Gate::before / AppServiceProvider.
     */
    public function canModule(string $moduleSlug, string $level): bool
    {
        if (! $this->role_id) return false;

        if ($this->hasRole('admin')) return true;

        $modulePerm = ModulePermission::where('role_id', $this->role_id)
            ->whereHas('module', fn($q) => $q->where('slug', $moduleSlug))
            ->first();

        return $modulePerm && $modulePerm->hasLevel($level);
    }

    /**
     * Récupère tous les modules accessibles par l'utilisateur (au moins lecture).
     */
    public function getAccessibleModules(): \Illuminate\Support\Collection
    {
        if (! $this->role_id) return collect();

        if ($this->hasRole('admin')) {
            return Module::active()->get();
        }

        $explicitModuleIds = ModulePermission::where('role_id', $this->role_id)
            ->where('can_read', true)
            ->pluck('module_id');

        return Module::active()->whereIn('id', $explicitModuleIds)->get();
    }

    /**
     * Matrice complète des permissions par module pour ce user.
     */
    public function getModulePermissionsMatrix(): array
    {
        $modules = Module::active()->get();
        $matrix = [];

        foreach ($modules as $module) {
            $matrix[$module->slug] = [
                'module' => $module,
                'L' => $this->canModule($module->slug, 'L'),
                'C' => $this->canModule($module->slug, 'C'),
                'M' => $this->canModule($module->slug, 'M'),
                'S' => $this->canModule($module->slug, 'S'),
            ];
        }

        return $matrix;
    }

    // ─── HELPERS ───

    public function hasRole(string $roleName): bool
    {
        $this->loadMissing('userRole');
        return $this->userRole && $this->userRole->name === $roleName;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasPermission('S');
    }

    /**
     * Le compte est-il actif (autorisé à se connecter) ?
     * Rétrocompatible : un null (colonne absente / ancien compte) = actif.
     */
    public function isActive(): bool
    {
        return $this->is_active === null ? true : (bool) $this->is_active;
    }

    /**
     * Route d'atterrissage après connexion, adaptée au profil.
     *
     * - Superviseurs (admin / manager / permission S) → tableau de bord global.
     * - Employé rattaché à une fiche RH → son espace personnel.
     * - Sinon → tableau de bord.
     */
    public function homeRoute(): string
    {
        if ($this->isSuperAdmin() || $this->hasRole('admin') || $this->hasRole('manager')) {
            return 'dashboard';
        }

        if ($this->employee()->exists()) {
            return 'mon-espace';
        }

        return 'dashboard';
    }
}
