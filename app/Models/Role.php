<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToFarm;

class Role extends Model
{
    use HasFactory, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'name',         // Nom système (ex: manager-provenderie)
        'display_name', // Nom affiché (ex: Manager Provenderie)
        'icon',         // Icone UI (ex: 🏗️)
        'label',
        'description',
        'permissions',  // Matrice LCMS globale (ex: ["L","C","M","S"])
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
     * Vérifie si ce rôle contient une permission spécifique (L, C, M, S).
     * Stockée dans la colonne JSON `permissions`.
     */
    public function hasPermission(string $permissionName): bool
    {
        return in_array($permissionName, $this->permissions ?? []);
    }

    /**
     * Helper pour identifier les administrateurs système.
     */
    public function isSupervisionRole(): bool
    {
        return $this->hasPermission('S');
    }
}