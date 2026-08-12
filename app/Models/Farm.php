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

    /**
     * SITES SUR LESQUELS LE TRAVAIL AUTOMATISÉ DOIT PORTER — déclaration unique.
     *
     * Étant un scope Eloquent, il exclut AUSSI les sites supprimés (SoftDeletes).
     * C'est le point qui manquait : cinq tâches planifiées écrivaient
     * `Farm::withoutGlobalScopes()->where('is_active', true)`, et
     * `withoutGlobalScopes()` retire TOUS les scopes — dont celui des suppressions.
     * Or Farm n'a délibérément PAS le trait BelongsToFarm (c'est écrit plus haut
     * dans ce fichier) : sur ce modèle, `withoutGlobalScopes()` ne retire donc
     * strictement rien d'utile, seulement la protection des suppressions.
     *
     * Mesuré : sur une base portant une ferme active et une ferme supprimée,
     * `Farm::active()` en rend 1 et `Farm::withoutGlobalScopes()->where('is_active',
     * true)` en rend 2.
     *
     * Et `tasks:generate` ne filtrait rien du tout — `DB::table('farms')->pluck('id')`
     * — si bien qu'un site désactivé OU supprimé recevait ses tâches quotidiennes
     * chaque matin à 05:00, indéfiniment. Mesuré : 4 sites parcourus dont un inactif
     * et un supprimé, 4 tâches créées pour chacun.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * PEUT-ON ENCORE TRAVAILLER DANS CE SITE ? — déclaration unique.
     *
     * Cette règle était écrite trois fois, et une seule était juste :
     *
     *   • le SÉLECTEUR de site (SetCurrentFarm) exigeait bien `is_active = true` ET
     *     `deleted_at IS NULL` — correct ;
     *   • `switchFarm()` ne vérifiait QUE le rattachement pivot : on pouvait donc
     *     basculer dans un site désactivé, ou supprimé ;
     *   • la résolution de la ferme courante employait `withoutGlobalScopes()`, qui
     *     sur ce modèle ne retire que la protection des suppressions : un site
     *     supprimé restait affiché comme site courant.
     *
     * Conséquence : un site désactivé restait pleinement utilisable par qui y était
     * rattaché — il pouvait y basculer, l'en-tête le nommait, et toutes ses saisies
     * y allaient — pendant que le sélecteur faisait comme s'il n'existait plus. Le
     * geste de « désactiver un site » ne désactivait donc rien pour ces comptes.
     *
     * ─── CE QUE CETTE MÉTHODE NE FAIT PAS, ET POURQUOI ───
     *
     * Elle ne dit RIEN du rattachement de l'utilisateur, délibérément. Ma première
     * version le mêlait à la question, et cela m'a coûté 412 tests d'un coup : un
     * compte SANS ligne dans `farm_user` retombe volontairement sur la ferme par
     * défaut (cf. resolveDefaultFarm et son miroir SetApiFarmContext) — sans ferme en
     * session, le FarmScope ne filtrerait plus rien, et la fuite inter-fermes se
     * ferait en « fail-open ».
     *
     * Mêler les deux règles revenait aussi à laisser ce contrôle ÉCRASER une ferme
     * explicitement choisie. Le rattachement se vérifie là où il se vérifiait déjà ;
     * ici on ne répond qu'à « ce site tourne-t-il encore ? ».
     */
    public static function isUsable(?int $farmId): bool
    {
        return $farmId !== null && static::active()->whereKey($farmId)->exists();
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
