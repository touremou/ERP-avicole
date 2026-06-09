<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    /**
     * Rigueur : Les noms de permissions sont généralement courts (L, C, M, S)
     */
    protected $fillable = [
        'name', 
        'description'
    ];

    /**
     * Désactivation des timestamps si vous jugez qu'ils sont inutiles 
     * pour des permissions statiques, sinon conservez-les par défaut.
     */
    public $timestamps = true;

    // -----------------------
    // RELATIONS
    // -----------------------

    /**
     * Relation inverse : Une permission est associée à plusieurs rôles.
     * Table pivot : permission_role
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role')->withTimestamps();
    }

    // -----------------------
    // LOGIQUE DE RÉFÉRENTIEL
    // -----------------------

    /**
     * Scope pour récupérer les grades de base.
     */
    public function scopeCoreGrades($query)
    {
        return $query->whereIn('name', ['L', 'C', 'M', 'S']);
    }

    /**
     * Accesseur pour une description plus explicite dans l'UI d'administration.
     */
    public function getLongLabelAttribute(): string
    {
        return match($this->name) {
            'L' => 'Lecture (Consultation des rapports et listes)',
            'C' => 'Création (Saisie des données et opérations)',
            'M' => 'Modification (Édition et clôture de lots)',
            'S' => 'Suppression/Système (Administration totale)',
            default => $this->description ?? $this->name,
        };
    }
}