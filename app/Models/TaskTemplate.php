<?php

// ═══ app/Models/TaskTemplate.php ═══

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTemplate extends Model
{
    use BelongsToFarm;

    /** Types de preuve d'exécution exigée à la complétion d'une tâche. */
    public const PROOF_TYPES = ['aucune', 'photo', 'valeur'];

    protected $fillable = [
        'farm_id', 'name', 'category', 'description', 'icon', 'color',
        'frequency', 'days_of_week', 'day_of_month', 'months', 'scheduled_time',
        'duration_minutes', 'target_type', 'per_building', 'batch_types',
        'plot_types', 'priority', 'is_active', 'is_pool',
        'proof_type', 'proof_label', 'proof_unit',
    ];

    protected $casts = [
        'days_of_week'  => 'array',
        'months'        => 'array',
        'batch_types'   => 'array',
        'plot_types'    => 'array',
        'per_building'  => 'boolean',
        'is_active'     => 'boolean',
        'is_pool'       => 'boolean',
    ];

    public function assignments(): HasMany { return $this->hasMany(TaskAssignment::class); }

    /**
     * Route model binding : ignorer le FarmScope (templates globaux).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::withoutGlobalScopes()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->firstOrFail();
    }

    public function scopeActive($q) { return $q->where('is_active', true); }

    /**
     * Options de « types de lots » proposées dans les formulaires de
     * template (filtre batch_types). Multi-espèces : on dérive la liste des
     * slugs DISTINCTS réellement présents dans production_types (ovins,
     * caprins, bovins, poissons, lapins, porcins… et pas seulement la
     * volaille), pour que le filtre du planificateur (qui matche sur
     * productionType.slug) couvre tout le cheptel.
     *
     * Retourne [slug => libellé lisible]. Un libellé canonique est fourni
     * pour les slugs connus ; tout nouveau slug reçoit un repli générique.
     *
     * @return array<string,string>
     */
    /**
     * CATÉGORIES DE TÂCHES — déclaration unique.
     *
     * La liste vivait en CINQ exemplaires, tous différents :
     *
     *   • getCategoryLabelAttribute()          12 catégories ;
     *   • le tableau $catMeta du catalogue      14 ;
     *   • les quatre <select> des formulaires    6 — l'élevage seulement ;
     *   • la validation                        « string|max:50 », donc tout ;
     *   • la carte catégorie → service du contrôleur  6.
     *
     * Conséquence signalée depuis le terrain : les modèles de tâches agricoles
     * s'affichaient correctement au catalogue, mais on ne pouvait CRÉER aucune
     * tâche d'irrigation, de semis ou de relevé — le menu déroulant n'offrait que
     * les six catégories d'élevage. Un arrosage se rangeait donc sous
     * « ALIMENTATION », et le planificateur devenait illisible.
     *
     * `departments` porte les services autorisés à recevoir la catégorie (cf.
     * Employee::DEPARTMENTS) ; `null` = aucune restriction. Cette carte vivait
     * dans TaskController et ne connaissait que les six catégories d'élevage :
     * une tâche agricole n'était donc soumise à aucun contrôle, tandis qu'un
     * nettoyage était refusé à tout autre service que l'Élevage — alors qu'on
     * nettoie aussi la provenderie et l'abattoir.
     *
     * Les catégories de CULTURES admettent « Elevage » en plus de « Cultures » :
     * les techniciens de cultures existants sont classés « Élevage / Technique »,
     * faute d'un service dédié jusqu'ici. Les leur refuser du jour au lendemain
     * bloquerait le planning.
     *
     * `controle`, `maintenance` et les relevés ne sont PAS restreints : ils se
     * pratiquent dans tous les ateliers.
     *
     * @var array<string, array{label: string, emoji: string, icon: string, color: string, group: string, departments: ?array}>
     */
    public const CATEGORIES = [
        // ── Élevage ──
        'alimentation'   => ['label' => 'Alimentation',   'emoji' => '🌾', 'icon' => 'fa-bowl-food',          'color' => 'amber', 'group' => 'Élevage', 'departments' => ['Elevage']],
        'collecte'       => ['label' => 'Collecte',       'emoji' => '🥚', 'icon' => 'fa-egg',                'color' => 'emerald', 'group' => 'Élevage', 'departments' => ['Elevage']],
        'controle'       => ['label' => 'Contrôle',       'emoji' => '📋', 'icon' => 'fa-clipboard-check',    'color' => 'blue', 'group' => 'Élevage', 'departments' => null],
        'nettoyage'      => ['label' => 'Nettoyage',      'emoji' => '🧹', 'icon' => 'fa-broom',              'color' => 'purple', 'group' => 'Élevage', 'departments' => ['Elevage', 'Logistique', 'Provenderie', 'Abattoir']],
        'sante'          => ['label' => 'Santé',          'emoji' => '💉', 'icon' => 'fa-heart-pulse',        'color' => 'rose', 'group' => 'Élevage', 'departments' => ['Elevage']],
        'maintenance'    => ['label' => 'Maintenance',    'emoji' => '🔧', 'icon' => 'fa-wrench',             'color' => 'slate', 'group' => 'Élevage', 'departments' => null],

        // ── Cultures ──
        'semis'          => ['label' => 'Semis',          'emoji' => '🌱', 'icon' => 'fa-seedling',           'color' => 'lime', 'group' => 'Cultures', 'departments' => ['Cultures', 'Elevage']],
        'irrigation'     => ['label' => 'Irrigation',     'emoji' => '💧', 'icon' => 'fa-droplet',            'color' => 'cyan', 'group' => 'Cultures', 'departments' => ['Cultures', 'Elevage']],
        'sarclage'       => ['label' => 'Sarclage',       'emoji' => '🌿', 'icon' => 'fa-trowel',             'color' => 'lime', 'group' => 'Cultures', 'departments' => ['Cultures', 'Elevage']],
        'fertilisation'  => ['label' => 'Fertilisation',  'emoji' => '⚗️', 'icon' => 'fa-flask',              'color' => 'green', 'group' => 'Cultures', 'departments' => ['Cultures', 'Elevage']],
        'traitement'     => ['label' => 'Traitement',     'emoji' => '🧪', 'icon' => 'fa-spray-can-sparkles', 'color' => 'rose', 'group' => 'Cultures', 'departments' => ['Cultures', 'Elevage']],
        'recolte'        => ['label' => 'Récolte',        'emoji' => '🧺', 'icon' => 'fa-basket-shopping',    'color' => 'emerald', 'group' => 'Cultures', 'departments' => ['Cultures', 'Elevage']],

        // ── Relevés de compteurs ──
        'releve_eau'     => ['label' => 'Relevé eau',     'emoji' => '🚰', 'icon' => 'fa-water',              'color' => 'cyan', 'group' => 'Relevés', 'departments' => null],
        'releve_energie' => ['label' => 'Relevé énergie', 'emoji' => '⚡', 'icon' => 'fa-bolt',               'color' => 'yellow', 'group' => 'Relevés', 'departments' => null],
    ];

    /**
     * Options du menu déroulant : [slug => « emoji Libellé »], libellés traduits.
     */
    public static function categoryOptions(): array
    {
        $options = [];

        foreach (self::CATEGORIES as $slug => $meta) {
            $options[$slug] = $meta['emoji'] . ' ' . __($meta['label']);
        }

        return $options;
    }

    /**
     * Options regroupées par domaine : [« Élevage » => [slug => libellé], …].
     * Quatorze options à plat noieraient l'opérateur ; les <optgroup> gardent le
     * menu lisible tout en ouvrant enfin les catégories agricoles.
     */
    public static function categoryOptionGroups(): array
    {
        $groups = [];

        foreach (self::CATEGORIES as $slug => $meta) {
            $groups[$meta['group']][$slug] = $meta['emoji'] . ' ' . __($meta['label']);
        }

        return $groups;
    }

    /**
     * Services autorisés pour une catégorie, ou null si elle n'est pas restreinte.
     */
    public static function categoryDepartments(string $slug): ?array
    {
        return self::CATEGORIES[$slug]['departments'] ?? null;
    }

    /** Métadonnées d'affichage d'une catégorie (libellé traduit, icône, couleur). */
    public static function categoryMeta(string $slug): array
    {
        $meta = self::CATEGORIES[$slug] ?? null;

        if ($meta === null) {
            // Catégorie héritée d'anciennes données : elle reste lisible.
            return ['label' => ucfirst(str_replace('_', ' ', $slug)),
                    'emoji' => '🏷️', 'icon' => 'fa-tag', 'color' => 'slate'];
        }

        return ['label' => __($meta['label'])] + $meta;
    }

    public static function batchTypeOptions(): array
    {
        $labels = [
            'chair'         => '🍗 Chair',
            'ponte'         => '🥚 Ponte',
            'reproducteur'  => '🧬 Reproducteur',
            'poussiniere'   => '🐣 Poussinière',
            'engraissement' => '🥩 Engraissement',
            'laitiere'      => '🥛 Laitière',
            'grossissement' => '🐟 Grossissement',
            'alevinage'     => '🐠 Alevinage',
        ];

        $slugs = ProductionType::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('slug')
            ->pluck('slug')
            ->all();

        $options = [];
        foreach ($slugs as $slug) {
            $options[$slug] = $labels[$slug] ?? ucfirst(str_replace('_', ' ', $slug));
        }

        return $options;
    }

    public function getCategoryLabelAttribute(): string
    {
        $meta = self::categoryMeta((string) $this->category);

        return $meta['emoji'] . ' ' . $meta['label'];
    }

    public static function plotTypeOptions(): array
    {
        return [
            'cereale'     => '🌾 Céréales',
            'tubercule'   => '🥔 Tubercules',
            'legumineuse' => '🫘 Légumineuses',
            'maraicher'   => '🥕 Maraîchage',
            'fruitier'    => '🍋 Fruitiers',
            'oleagineux'  => '🌻 Oléagineux',
            'legume'      => '🥬 Légumes feuillus',
            'autre'       => '🌱 Autres',
        ];
    }

    /**
     * Vérifie si ce template doit être généré pour un jour donné.
     */
    public function shouldRunOnDay(\Carbon\Carbon $date): bool
    {
        if (! $this->is_active) return false;

        // SAISONNALITÉ (S1) : un arrosage quotidien généré toute l'année tourne
        // aussi en pleine saison des pluies. Une tâche sans objet ce jour-là
        // apprend au technicien à cocher sans faire — et empoisonne le
        // dénominateur du taux de complétion, donc la mesure elle-même.
        // null = tous les mois (comportement historique inchangé).
        if (! empty($this->months) && ! in_array((int) $date->month, array_map('intval', $this->months), true)) {
            return false;
        }

        return match($this->frequency) {
            'quotidien'  => $this->days_of_week === null || in_array($date->dayOfWeekIso, $this->days_of_week ?? []),
            'hebdo'      => in_array($date->dayOfWeekIso, $this->days_of_week ?? []),
            'mensuel'    => $date->day === $this->day_of_month,
            // 'ponctuel' : JAMAIS auto-généré, et c'est voulu. Une récolte ne se
            // planifie pas au calendrier : elle vient de l'itinéraire technique
            // (étape « recolte » en jours après semis), via
            // TaskSchedulerService::generateProtocolTasks. Un template ponctuel
            // reste utile en création manuelle.
            default      => false,
        };
    }

    /** Libellé des mois d'activité (« toute l'année » si non restreint). */
    public function getMonthsLabelAttribute(): string
    {
        if (empty($this->months)) {
            return 'Toute l\'année';
        }

        $names = [1 => 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
        $picked = array_map(fn ($m) => $names[(int) $m] ?? $m, $this->months);

        return implode(' · ', $picked);
    }
}
