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
        return match($this->category) {
            'alimentation' => '🌾 Alimentation',
            'collecte'     => '🥚 Collecte',
            'controle'     => '📋 Contrôle',
            'nettoyage'    => '🧹 Nettoyage',
            'sante'        => '💉 Santé',
            'maintenance'  => '🔧 Maintenance',
            'irrigation'   => '💧 Irrigation',
            'sarclage'     => '🌿 Sarclage',
            'traitement'   => '🌾 Traitement',
            'fertilisation'=> '⚗️ Fertilisation',
            'recolte'      => '🧺 Récolte',
            'semis'        => '🌱 Semis',
            default        => $this->category,
        };
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
