<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionType extends Model
{
    protected $fillable = [
        'species_id','slug','name_fr','metrics_enabled','kpi_primary','cycle_days_default','is_active',
    ];

    protected $casts = [
        'metrics_enabled'  => 'array',
        'is_active'        => 'boolean',
        'cycle_days_default' => 'integer',
    ];

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function tracks(string $metric): bool
    {
        return (bool) ($this->metrics_enabled[$metric] ?? false);
    }

    public function getKpiLabelAttribute(): string
    {
        return match($this->kpi_primary) {
            'fcr'      => 'Indice de Consommation',
            'hdp'      => 'Taux de Ponte (HDP)',
            'gmq'      => 'Gain Moyen Quotidien',
            'survie'   => 'Taux de Survie',
            'hdp_lait' => 'Production Laitière',
            'gdq'      => 'Gain de poids/jour',
            default    => $this->kpi_primary,
        };
    }
}
