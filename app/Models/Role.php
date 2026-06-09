<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\BelongsToFarm;

class Role extends Model
{
    use HasFactory, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'name',         // Nom système (ex: manager-provenderie)
        'display_name', // Nom affiché (ex: Manager Provenderie)
        'icon'          // Icone UI (ex: 🏗️)
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    /**
     * Un rôle possède plusieurs privilèges (Matrice L, C, M, S).
     * Table pivot : permission_role
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role')->withTimestamps();
    }

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
     * Vérifie si ce rôle contient une permission spécifique.
     * Rigueur Senior : Utilise la collection chargée pour éviter les requêtes SQL en boucle.
     * * @param string $permissionName
     * @return bool
     */
    public function hasPermission(string $permissionName): bool
    {
        // On vérifie si la relation est chargée, sinon on utilise contains qui lancera une requête
        // Mais dans l'ERP, on privilégie le Eager Loading (User::with('userRole.permissions'))
        return $this->permissions->contains('name', $permissionName);
    }

    /**
     * Helper pour identifier les administrateurs système.
     */
    public function isSupervisionRole(): bool
    {
        return $this->hasPermission('S');
    }
}