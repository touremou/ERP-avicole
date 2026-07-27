<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Species extends Model
{
    protected $fillable = [
        'slug','name_fr','local_name','family','unit_label','habitat_label',
        'icon','color','tracks_eggs','tracks_milk','tracks_water_quality',
        'is_active','sort_order','farm_id','incubation_days',
    ];

    protected $casts = [
        'tracks_eggs'         => 'boolean',
        'tracks_milk'         => 'boolean',
        'tracks_water_quality'=> 'boolean',
        'is_active'           => 'boolean',
        'incubation_days'     => 'integer',
    ];

    /**
     * Durée d'incubation en jours — SOURCE UNIQUE.
     *
     * Le nombre vivait en trois endroits qui pouvaient se contredire : un tableau
     * codé en dur dans IncubationController, le repli « 21 » de StartIncubation
     * (qui ignorait ce tableau, datant l'éclosion d'un canard à 21 jours au lieu
     * de 28), et le réglage `couvoir.incubation_days` lu par la seule barre de
     * progression.
     *
     * Cascade assumée : valeur de l'espèce → réglage de la ferme → 21 (la poule).
     * Une espèce ajoutée par l'utilisateur sans durée renseignée retombe donc sur
     * le réglage, que la ferme peut fixer — et non sur une constante enfouie.
     */
    public function incubationDays(): int
    {
        return (int) ($this->incubation_days
            ?: setting('couvoir.incubation_days', 21));
    }

    /**
     * Durées par slug d'espèce, pour les formulaires : { poulet: 21, canard: 28 }.
     * Remplace le tableau que le contrôleur portait en dur.
     *
     * @return array<string, int>
     */
    public static function incubationDurations(): array
    {
        return static::query()
            ->whereNotNull('incubation_days')
            ->pluck('incubation_days', 'slug')
            ->map(fn ($days) => (int) $days)
            ->all();
    }

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

    /**
     * Vérifie qu'un bâtiment peut accueillir un lot de cette espèce pour le
     * type/phase de production visé.
     *
     * Source unique de vérité partagée par App\Http\Requests\Batch\StoreBatchRequest,
     * UpdateBatchRequest et TransferBatchRequest. Un bâtiment 'mixte' accepte
     * toujours. Pour les espèces référencées dans config('livestock.building_types')
     * (non-volailles), l'habitat est dédié à l'ESPÈCE quelle que soit la phase.
     * Pour les autres (volaille), on compare le type de bâtiment au slug du
     * type de production visé.
     */
    public static function buildingIsCompatible(Building $building, ?self $species, string $targetType): bool
    {
        if ($building->type === 'mixte') {
            return true;
        }

        $compatibleTypes = $species?->compatibleBuildingTypes();

        return $compatibleTypes !== null
            ? in_array($building->type, $compatibleTypes, true)
            : $building->type === $targetType;
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
