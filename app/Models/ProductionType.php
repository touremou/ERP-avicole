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

    /**
     * Secteur d'aliment associé à ce type de production (cf.
     * Batch::FEED_PHASES). Source de vérité partagée par les lots (Batch) et
     * les formules de provenderie (Formula).
     *
     * Volaille : « Chair » ou « Ponte » (ponte/repro/reproducteur → Ponte).
     * Autres espèces : Engraissement / Laitière / Reproducteur /
     * Grossissement / Alevinage selon le slug. En l'absence d'espèce, on
     * retombe sur la logique volaille (rétrocompat mono-espèce).
     */
    public function feedSector(): string
    {
        $slug = strtolower((string) $this->slug);

        if ($this->species?->isVolaille() ?? true) {
            return in_array($slug, ['ponte', 'repro', 'reproducteur'], true)
                ? 'Ponte'
                : 'Chair';
        }

        return match ($slug) {
            'laitiere'              => 'Laitière',
            'grossissement'         => 'Grossissement',
            'alevinage'             => 'Alevinage',
            'repro', 'reproducteur' => 'Reproducteur',
            default                 => 'Engraissement',
        };
    }

    /**
     * Retrouve (ou crée) le type de production correspondant à un slug
     * legacy (ex. 'chair', 'ponte') pour l'espèce donnée. À défaut d'espèce,
     * retombe sur « poulet » (rétrocompat lots volaille mono-espèce).
     *
     * Utilisé à l'écriture (création/modification de lot) pour traduire le
     * champ `type` du formulaire en `production_type_id`, désormais source
     * de vérité.
     */
    public static function resolveOrCreate(string $slug, ?int $speciesId): self
    {
        $speciesId ??= Species::where('slug', 'poulet')->value('id');

        return static::firstOrCreate(
            ['species_id' => $speciesId, 'slug' => $slug],
            ['name_fr' => ucfirst($slug), 'is_active' => true]
        );
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
