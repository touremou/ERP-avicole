<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /**
     * VOLUME DE DONNÉES rattaché à ce site, table par table.
     *
     * Sert à décider si un site peut être SUPPRIMÉ. Un site est la racine de tout
     * ce qui s'y produit — lots, ventes, paie, présences, stocks : une suppression
     * qui cascade détruirait des années d'écritures, une suppression qui n'en
     * tient pas compte laisserait des lignes orphelines. Les deux sont pires que
     * de refuser.
     *
     * On ÉNUMÈRE les tables portant un farm_id au lieu de tenir une liste à la
     * main : une liste manuscrite oublie la table ajoutée le mois suivant, et
     * l'oubli ne se voit qu'au moment où l'on supprime — trop tard. Le pivot
     * `farm_user` est écarté : un simple droit d'accès n'est pas une écriture
     * d'exploitation.
     *
     * @return array<string, int>  Table => nombre de lignes, tables vides exclues.
     */
    public function dataCounts(): array
    {
        $counts = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table)[1] : $table;

            if ($table === 'farm_user' || $table === 'farms') {
                continue;
            }

            if (! Schema::hasColumn($table, 'farm_id')) {
                continue;
            }

            $count = DB::table($table)->where('farm_id', $this->id)->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }

    /** Un site sans aucune écriture peut être supprimé sans rien perdre. */
    public function isEmpty(): bool
    {
        return $this->dataCounts() === [];
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
