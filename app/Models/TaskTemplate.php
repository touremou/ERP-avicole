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
        'priority', 'is_active',
    ];

    protected $casts = [
        'days_of_week'  => 'array',
        'batch_types'   => 'array',
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

    public function getCategoryLabelAttribute(): string
    {
        return match($this->category) {
            'alimentation' => '🌾 Alimentation',
            'collecte'     => '🥚 Collecte',
            'controle'     => '📋 Contrôle',
            'nettoyage'    => '🧹 Nettoyage',
            'sante'        => '💉 Santé',
            'maintenance'  => '🔧 Maintenance',
            default        => $this->category,
        };
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
