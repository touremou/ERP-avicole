<?php

// ═══ app/Models/TaskTemplate.php ═══

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTemplate extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'name', 'category', 'description', 'icon', 'color',
        'frequency', 'days_of_week', 'day_of_month', 'scheduled_time',
        'duration_minutes', 'target_type', 'per_building', 'batch_types',
        'plot_types', 'priority', 'is_active',
    ];

    protected $casts = [
        'days_of_week'  => 'array',
        'batch_types'   => 'array',
        'plot_types'    => 'array',
        'per_building'  => 'boolean',
        'is_active'     => 'boolean',
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

        return match($this->frequency) {
            'quotidien'  => $this->days_of_week === null || in_array($date->dayOfWeekIso, $this->days_of_week ?? []),
            'hebdo'      => in_array($date->dayOfWeekIso, $this->days_of_week ?? []),
            'mensuel'    => $date->day === $this->day_of_month,
            default      => false,
        };
    }
}
