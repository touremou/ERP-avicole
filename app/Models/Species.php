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
     * PROPORTION DE REPRODUCTEURS — mâles pour 100 femelles, par espèce.
     *
     * La création de bande appelait ce rapport « ratio de coquage » et ne le
     * jugeait jamais (« En attente… » restait affiché) ; la planification le
     * jugeait sur UN seul réglage, celui du poulet (8–12 %), pour toutes les
     * espèces : 1 bélier pour 30 brebis (3,3 %) y était « trop peu de mâles »,
     * 4 béliers pour 10 brebis (40 %) passait inaperçu à la création.
     *
     * [min %, max %, le mâle, les femelles] — valeurs de conduite usuelles en
     * monte naturelle. Le poulet garde le réglage de la ferme
     * (`elevage.mating_ratio_min` / `_max`).
     */
    public const RATIOS_REPRODUCTEURS = [
        'poulet'  => [8,   12,   'coq',       'poules'],
        'dinde'   => [8,   12,   'dindon',    'dindes'],
        'pintade' => [17,  25,   'mâle',      'pintades'],
        'caille'  => [33,  50,   'mâle',      'cailles'],
        'canard'  => [12,  20,   'canard',    'canes'],
        'pigeon'  => [90,  110,  'mâle',      'femelles'],
        'mouton'  => [2.5, 4,    'bélier',    'brebis'],
        'chevre'  => [3,   5,    'bouc',      'chèvres'],
        'vache'   => [2.5, 4,    'taureau',   'vaches'],
        'porc'    => [4,   7,    'verrat',    'truies'],
        'lapin'   => [10,  12.5, 'mâle',      'lapines'],
        'tilapia' => [33,  50,   'mâle',      'femelles'],
        'carpe'   => [100, 200,  'mâle',      'femelles'],
        'silure'  => [80,  120,  'mâle',      'femelles'],
    ];

    /**
     * La référence de l'espèce, ou null si elle n'en a pas.
     *
     * @return array{min: float, max: float, male: string, femelles: string}|null
     */
    /**
     * Les références de toutes les espèces, par slug — posées une fois sur les
     * écrans qui saisissent des reproducteurs (resources/js/breeding-ratio.js).
     *
     * @return array<string, array{min: float, max: float, male: string, femelles: string}>
     */
    public static function ratiosParSlug(): array
    {
        return collect(array_keys(self::RATIOS_REPRODUCTEURS))
            ->mapWithKeys(fn (string $slug) => [$slug => (new self(['slug' => $slug]))->ratioReproducteurs()])
            ->all();
    }

    public function ratioReproducteurs(): ?array
    {
        $ref = self::RATIOS_REPRODUCTEURS[$this->slug] ?? null;
        if (! $ref) {
            return null;
        }

        [$min, $max, $male, $femelles] = $ref;
        if ($this->slug === 'poulet') {
            $min = (float) setting('elevage.mating_ratio_min', $min);
            $max = (float) setting('elevage.mating_ratio_max', $max);
        }

        return ['min' => (float) $min, 'max' => (float) $max, 'male' => __($male), 'femelles' => __($femelles)];
    }

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

    /**
     * LE VOCABULAIRE DE L'ESPÈCE — une déclaration, à l'image de habitat_label.
     *
     * Les écrans communs à toutes les espèces parlaient volaille : la clôture
     * d'un bassin de tilapias annonçait « Acquisition poussins » et « Vente
     * volaille », le planning d'une bande de porcs « Poussins commandés ».
     *
     *   jeunes : ce que l'on met en place en démarrant une bande ;
     *   vente  : ce que l'on vend vif à la clôture.
     */
    public const VOCABULAIRE = [
        'poulet'  => ['jeunes' => 'poussins',     'vente' => 'volaille'],
        'dinde'   => ['jeunes' => 'dindonneaux',  'vente' => 'volaille'],
        'caille'  => ['jeunes' => 'cailleteaux',  'vente' => 'volaille'],
        'pigeon'  => ['jeunes' => 'pigeonneaux',  'vente' => 'volaille'],
        'pintade' => ['jeunes' => 'pintadeaux',   'vente' => 'volaille'],
        'canard'  => ['jeunes' => 'canetons',     'vente' => 'volaille'],
        'mouton'  => ['jeunes' => 'agneaux',      'vente' => 'ovins'],
        'chevre'  => ['jeunes' => 'chevreaux',    'vente' => 'caprins'],
        'vache'   => ['jeunes' => 'veaux',        'vente' => 'bovins'],
        'tilapia' => ['jeunes' => 'alevins',      'vente' => 'poissons'],
        'carpe'   => ['jeunes' => 'alevins',      'vente' => 'poissons'],
        'silure'  => ['jeunes' => 'alevins',      'vente' => 'poissons'],
        'lapin'   => ['jeunes' => 'lapereaux',    'vente' => 'lapins'],
        'porc'    => ['jeunes' => 'porcelets',    'vente' => 'porcs'],
    ];

    /** Repli par famille, pour une espèce ajoutée par la ferme. */
    private const VOCABULAIRE_FAMILLE = [
        'volaille'       => ['jeunes' => 'poussins',  'vente' => 'volaille'],
        'aquaculture'    => ['jeunes' => 'alevins',   'vente' => 'poissons'],
        'petit_ruminant' => ['jeunes' => 'jeunes',    'vente' => 'animaux'],
        'grand_ruminant' => ['jeunes' => 'veaux',     'vente' => 'bovins'],
        'porcin'         => ['jeunes' => 'porcelets', 'vente' => 'porcs'],
        'lagomorphe'     => ['jeunes' => 'lapereaux', 'vente' => 'lapins'],
    ];

    /** « poussins », « alevins », « porcelets »… — ce que l'on met en place. */
    public function jeunes(): string
    {
        return self::VOCABULAIRE[$this->slug]['jeunes']
            ?? self::VOCABULAIRE_FAMILLE[$this->family]['jeunes']
            ?? 'jeunes';
    }

    /** « volaille », « poissons », « ovins »… — ce que l'on vend vif. */
    public function vente(): string
    {
        return self::VOCABULAIRE[$this->slug]['vente']
            ?? self::VOCABULAIRE_FAMILLE[$this->family]['vente']
            ?? 'animaux';
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
