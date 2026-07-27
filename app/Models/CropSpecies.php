<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasStandardUuid;

/**
 * Espèce / culture du catalogue agronomique (module Production Végétale).
 *
 * Référentiel partagé (non multi-ferme) servant de base de connaissances :
 * durée de cycle, rendement de référence, nom local guinéen. Pré-remplit un
 * cycle de culture et sert de benchmark au rendement réel.
 */
class CropSpecies extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid;

    protected $table = 'crop_species';

    /** Types de cultures (libellés FR + icône Font Awesome pour l'affichage). */
    public const TYPES = [
        'cereale'     => ['label' => 'Céréales',     'icon' => 'fa-wheat-awn',     'color' => 'amber'],
        'legume'      => ['label' => 'Légumes',      'icon' => 'fa-leaf',          'color' => 'green'],
        'tubercule'   => ['label' => 'Tubercules',   'icon' => 'fa-carrot',        'color' => 'orange'],
        'fruitier'    => ['label' => 'Fruitiers',    'icon' => 'fa-apple-whole',   'color' => 'rose'],
        'legumineuse' => ['label' => 'Légumineuses', 'icon' => 'fa-seedling',      'color' => 'lime'],
        'oleagineux'  => ['label' => 'Oléagineux',   'icon' => 'fa-sun',           'color' => 'yellow'],
        'maraicher'   => ['label' => 'Maraîchers',   'icon' => 'fa-pepper-hot',    'color' => 'red'],
        'epice'       => ['label' => 'Épices & aromates', 'icon' => 'fa-mortar-pestle', 'color' => 'pink'],
        'autre'       => ['label' => 'Autre',        'icon' => 'fa-sprout',        'color' => 'slate'],
    ];

    /**
     * MATÉRIEL DE PLANTATION — ce qu'on met en terre.
     *
     * Le formulaire demandait « Quantité semence » en kg pour toute culture. On
     * ne plante pas un ananas en kilos de semence : on plante des REJETS, qui se
     * comptent à l'unité. Le technicien devait convertir mentalement ou laisser
     * le champ vide, et le coût de plantation devenait incomparable d'un cycle à
     * l'autre.
     */
    public const PLANTING_MATERIALS = [
        'semence'               => 'Semence',
        'plant'                 => 'Plant (pépinière)',
        'rejet'                 => 'Rejet',
        'bouture'               => 'Bouture',
        'tubercule'             => 'Tubercule',
        'fragment de tubercule' => 'Fragment de tubercule',
        'rhizome'               => 'Rhizome',
        'greffon'               => 'Greffon',
    ];

    /** Unités de comptage du matériel de plantation. */
    public const PLANTING_UNITS = ['kg', 'unité', 'botte', 'sac'];

    /**
     * Libellé du champ de quantité, adapté à la culture : « Quantité de rejets
     * (unité) » plutôt que « Quantité semence ». C'est cette formulation qui dit
     * au technicien ce qu'on attend de lui.
     */
    public function getPlantingLabelAttribute(): string
    {
        $material = $this->planting_material ?: 'semence';
        $unit = $this->planting_unit ?: 'kg';

        $plural = match ($material) {
            'semence'   => 'Quantité de semences',
            'plant'     => 'Nombre de plants',
            'rejet'     => 'Nombre de rejets',
            'bouture'   => 'Nombre de boutures',
            'greffon'   => 'Nombre de greffons',
            'tubercule' => 'Quantité de tubercules',
            'rhizome'   => 'Quantité de rhizomes',
            default     => 'Quantité de ' . $material,
        };

        return "{$plural} ({$unit})";
    }

    /** Quantité SUGGÉRÉE pour une surface donnée, d'après la densité de référence. */
    public function suggestedPlantingQuantity(?float $areaHa): ?float
    {
        if (! $this->planting_density || ! $areaHa || $areaHa <= 0) {
            return null;
        }

        $quantity = $this->planting_density * $areaHa;

        // Un demi-plant n'existe pas : on arrondit ce qui se compte à l'unité.
        return $this->planting_unit === 'kg' ? round($quantity, 2) : (float) round($quantity);
    }

    /** Zones agro-écologiques de Guinée (4 régions naturelles). */
    public const ZONES = [
        'basse_guinee'      => 'Basse-Guinée (Maritime)',
        'moyenne_guinee'    => 'Moyenne-Guinée (Fouta-Djalon)',
        'haute_guinee'      => 'Haute-Guinée',
        'guinee_forestiere' => 'Guinée Forestière',
    ];

    /** Suggestions canoniques de types de sol (le champ reste libre côté parcelle). */
    public const SOIL_TYPES = [
        'argileux', 'limoneux', 'sableux', 'argilo-limoneux', 'lateritique', 'humifere',
    ];

    /** Niveaux de besoin en eau (clé => libellé FR). */
    public const WATER_NEEDS = [
        'faible' => 'Faible',
        'moyen'  => 'Moyen',
        'eleve'  => 'Élevé',
    ];

    /** Abréviations FR des mois (index 1..12). */
    private const MONTH_ABBR = [
        1 => 'Janv.', 2 => 'Févr.', 3 => 'Mars', 4 => 'Avr.', 5 => 'Mai', 6 => 'Juin',
        7 => 'Juil.', 8 => 'Août', 9 => 'Sept.', 10 => 'Oct.', 11 => 'Nov.', 12 => 'Déc.',
    ];

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'type', 'name', 'local_name', 'family',
        'cycle_days_min', 'cycle_days_max', 'avg_yield_tha',
        'planting_material', 'planting_unit', 'planting_density',
        'sowing_months', 'soil_types', 'agro_zones', 'water_need', 'yield_tips',
        'description', 'is_active',
    ];

    protected $casts = [
        'is_synced'      => 'boolean',
        'last_sync_at'   => 'datetime',
        'is_active'      => 'boolean',
        'cycle_days_min' => 'integer',
        'cycle_days_max' => 'integer',
        'avg_yield_tha'  => 'decimal:2',
        'sowing_months'  => 'array',
        'soil_types'     => 'array',
        'agro_zones'     => 'array',
    ];

    // ─── RELATIONS ───

    public function varieties(): HasMany
    {
        return $this->hasMany(CropVariety::class);
    }

    // ─── SCOPES ───

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ─── ACCESSEURS ───

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type]['label'] ?? ucfirst((string) $this->type);
    }

    public function getTypeIconAttribute(): string
    {
        return self::TYPES[$this->type]['icon'] ?? 'fa-sprout';
    }

    public function getTypeColorAttribute(): string
    {
        return self::TYPES[$this->type]['color'] ?? 'slate';
    }

    /** Durée de cycle lisible (« 90–120 j » ou « 90 j »). */
    public function getCycleLabelAttribute(): ?string
    {
        if (! $this->cycle_days_min && ! $this->cycle_days_max) {
            return null;
        }
        if ($this->cycle_days_min && $this->cycle_days_max && $this->cycle_days_min !== $this->cycle_days_max) {
            return "{$this->cycle_days_min}–{$this->cycle_days_max} j";
        }

        return ($this->cycle_days_min ?: $this->cycle_days_max) . ' j';
    }

    /**
     * Fenêtre de semis lisible à partir des numéros de mois.
     * Mois contigus (croissants) → plage « Mai – Juil. », sinon liste « Mai, Oct. ».
     */
    public function getSowingLabelAttribute(): ?string
    {
        $months = $this->sowing_months;
        if (empty($months) || ! is_array($months)) {
            return null;
        }

        $months = array_values(array_unique(array_map('intval', $months)));
        sort($months);
        $months = array_filter($months, fn ($m) => $m >= 1 && $m <= 12);
        if (empty($months)) {
            return null;
        }

        $isContiguous = true;
        for ($i = 1; $i < count($months); $i++) {
            if ($months[$i] !== $months[$i - 1] + 1) {
                $isContiguous = false;
                break;
            }
        }

        if (count($months) >= 2 && $isContiguous) {
            return self::MONTH_ABBR[$months[0]] . ' – ' . self::MONTH_ABBR[end($months)];
        }

        return implode(', ', array_map(fn ($m) => self::MONTH_ABBR[$m], $months));
    }

    /** Libellés des zones agro-écologiques favorables. */
    public function getZoneLabelsAttribute(): array
    {
        $zones = $this->agro_zones;
        if (empty($zones) || ! is_array($zones)) {
            return [];
        }

        return array_values(array_map(fn ($z) => self::ZONES[$z] ?? $z, $zones));
    }
}
