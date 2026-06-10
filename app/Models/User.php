<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role_id', 'whatsapp_phone',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    // ─── RELATIONS ───

    public function userRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
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
     * Logique de résolution :
     * 1. Si une permission module existe → l'utiliser
     * 2. Sinon → fallback sur la permission globale (LCMS classique)
     * 3. Les SuperAdmin (permission globale S) ont accès à tout
     */
    public function canModule(string $moduleSlug, string $level): bool
    {
        if (! $this->role_id) return false;

        // SuperAdmin bypass : permission S globale = accès total
        if ($this->hasPermission('S')) return true;

        // Chercher la permission spécifique au module
        $modulePerm = ModulePermission::where('role_id', $this->role_id)
            ->whereHas('module', fn($q) => $q->where('slug', $moduleSlug))
            ->first();

        // Si une permission module existe, l'utiliser
        if ($modulePerm) {
            return $modulePerm->hasLevel($level);
        }

        // Fallback : permission globale classique (rétrocompatibilité)
        return $this->hasPermission($level);
    }

    /**
     * Récupère tous les modules accessibles par l'utilisateur (au moins lecture).
     */
    public function getAccessibleModules(): \Illuminate\Support\Collection
    {
        if (! $this->role_id) return collect();

        // SuperAdmin : tous les modules
        if ($this->hasPermission('S')) {
            return Module::active()->get();
        }

        // Modules avec permission explicite (can_read = true)
        $explicitModuleIds = ModulePermission::where('role_id', $this->role_id)
            ->where('can_read', true)
            ->pluck('module_id');

        // Si aucune permission module n'est configurée → fallback : tous les modules si L global
        if (ModulePermission::where('role_id', $this->role_id)->count() === 0) {
            if ($this->hasPermission('L')) {
                return Module::active()->get();
            }
            return collect();
        }

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
}
