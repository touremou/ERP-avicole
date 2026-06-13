<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Model Farm — Table parente multi-site.
 *
 * ⚠️ NE PAS ajouter le trait BelongsToFarm ici !
 * La table farms est la table PARENTE, pas une table enfant.
 */
class Farm extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'code', 'address', 'city', 'region',
        'phone', 'email', 'manager_name', 'logo_path',
        'settings', 'is_active',
    ];

    protected $casts = [
        'settings'  => 'array',
        'is_active' => 'boolean',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'farm_user')
            ->withPivot(['is_default', 'is_owner'])
            ->withTimestamps();
    }

    public function owners(): BelongsToMany
    {
        return $this->users()->wherePivot('is_owner', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Identifiant de la ferme « par défaut » utilisée comme repli lorsqu'aucune
     * ferme courante n'est définie en session (création via seeder, factory,
     * console, ou tout contexte hors HTTP). On retient la première ferme active
     * — généralement la « Ferme Principale ». Le résultat est mémoïsé pour la
     * durée de la requête afin d'éviter des requêtes répétées à la création
     * en masse de modèles.
     */
    public static function defaultId(): ?int
    {
        static $cached = false;
        static $id = null;

        if ($cached === false) {
            $id = static::query()
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->value('id');
            $cached = true;
        }

        return $id ? (int) $id : null;
    }

    /**
     * Récupère un paramètre spécifique à la ferme.
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }
}
