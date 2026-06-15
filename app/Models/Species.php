<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Species extends Model
{
    protected $fillable = [
        'slug','name_fr','local_name','family','unit_label','habitat_label',
        'icon','color','tracks_eggs','tracks_milk','tracks_water_quality',
        'is_active','sort_order','farm_id',
    ];

    protected $casts = [
        'tracks_eggs'         => 'boolean',
        'tracks_milk'         => 'boolean',
        'tracks_water_quality'=> 'boolean',
        'is_active'           => 'boolean',
    ];

    public function productionTypes(): HasMany
    {
        return $this->hasMany(ProductionType::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByFamily($query, string $family)
    {
        return $query->where('family', $family);
    }

    public function isVolaille(): bool  { return $this->family === 'volaille'; }
    public function isRuminant(): bool  { return in_array($this->family, ['petit_ruminant','grand_ruminant']); }
    public function isAquaculture(): bool { return $this->family === 'aquaculture'; }

    /**
     * Types de bâtiment ('buildings.type') compatibles avec cette espèce, en
     * plus de 'mixte' (toujours autorisé).
     *
     * Retourne `null` pour les espèces avicoles (et toute espèce non
     * référencée) : la compatibilité se résout alors par égalité directe
     * entre le type de bâtiment et le slug du type de production visé
     * (cf. config/livestock.php).
     */
    public function compatibleBuildingTypes(): ?array
    {
        return config('livestock.building_types.' . $this->slug);
    }

    /** Familles suivies via le GMQ (croissance pondérale + portées) */
    public function isGmqTracked(): bool
    {
        return in_array($this->family, ['petit_ruminant', 'grand_ruminant', 'porcin', 'lagomorphe']);
    }

    public function getFamilyLabelAttribute(): string
    {
        return match($this->family) {
            'volaille'       => 'Volaille',
            'petit_ruminant' => 'Petit Ruminant',
            'grand_ruminant' => 'Grand Ruminant',
            'aquaculture'    => 'Pisciculture',
            'porcin'         => 'Porcin',
            'lagomorphe'     => 'Lapins',
            default          => 'Autre',
        };
    }

    /** Métriques activées par défaut selon la famille */
    public function getDefaultMetrics(): array
    {
        return match($this->family) {
            'aquaculture' => ['mortality'=>true,'feed'=>true,'weight'=>true,'water_quality'=>true,'eggs'=>false,'milk'=>false,'born'=>false,'weaned'=>false],
            'petit_ruminant','grand_ruminant' => ['mortality'=>true,'feed'=>true,'weight'=>true,'born'=>true,'weaned'=>true,'milk'=>$this->tracks_milk,'eggs'=>false,'water_quality'=>false],
            default => ['mortality'=>true,'feed'=>true,'weight'=>true,'eggs'=>$this->tracks_eggs,'milk'=>false,'water_quality'=>false,'born'=>false,'weaned'=>false],
        };
    }
}
