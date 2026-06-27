<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToFarm;
use App\Traits\AuditsChanges;

class Role extends Model
{
    use HasFactory, BelongsToFarm, AuditsChanges;

    protected $fillable = [
        'farm_id',
        'name',         // Nom système (ex: manager-provenderie)
        'display_name', // Nom affiché (ex: Manager Provenderie)
        'icon',         // Icone UI (ex: 🏗️)
        'label',
        'description',
        // DÉPRÉCIÉ comme source d'autorisation : la matrice `module_permissions`
        // est désormais l'UNIQUE autorité (cf. hasPermission ci-dessous et
        // AppServiceProvider). Cette colonne ne subsiste que comme entrée de
        // bootstrap pratique (seeders/tests) pour générer la matrice en une
        // passe. Elle n'est plus jamais lue pour décider d'un accès.
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    /**
     * Liste des utilisateurs rattachés à ce grade.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    // -----------------------
    // LOGIQUE DE SÉCURITÉ
    // -----------------------

    /**
     * Vérifie si ce rôle détient une permission (L, C, M, S) — SOURCE UNIQUE :
     * la matrice `module_permissions`. Renvoie vrai dès qu'au moins un module
     * accorde ce niveau au rôle (sémantique « globale » cohérente avec le Gate
     * L/C/M/S sans module ciblé dans AppServiceProvider).
     */
    public function hasPermission(string $permissionName): bool
    {
        $column = match ($permissionName) {
            'L' => 'can_read',
            'C' => 'can_create',
            'M' => 'can_modify',
            'S' => 'can_delete',
            default => null,
        };

        if ($column === null) {
            return false;
        }

        return ModulePermission::where('role_id', $this->id)
            ->where($column, true)
            ->exists();
    }

    /**
     * Helper pour identifier les administrateurs système.
     */
    public function isSupervisionRole(): bool
    {
        return $this->hasPermission('S');
    }
}