<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionNorm extends Model
{
    use HasFactory;

    protected $fillable = [
        'species_id',  // Espèce rattachée (null = souche générique, toutes espèces)
        'batch_type',
        'week_number',
        'phase_name',
        'model_name', // Ajout crucial pour identifier la souche (Ross, ISA, etc.)
        'cycle_days', // Durée de cycle PROPRE à la souche (null = celle du type de production)
        'target_weight',
        'target_feed_daily',
        'target_water_daily',
        'target_laying_rate',

        // Guide de souche détaillé (fiches officielles — nullable si inconnu) :
        // fourchettes conso/poids, uniformité cible, programme lumineux, T°.
        'feed_min_daily', 'feed_max_daily',
        'weight_min', 'weight_max',
        'uniformity_target',
        'light_hours', 'light_lux_min', 'light_lux_max',
        'temp_min_c', 'temp_max_c',
    ];

    /**
     * Espèce à laquelle la souche est rattachée (null = générique).
     */
    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    /**
     * Limite les souches à une espèce donnée. Les souches génériques
     * (species_id NULL) restent visibles pour toutes les espèces.
     */
    public function scopeForSpecies($query, $speciesId)
    {
        return $query->where(function ($q) use ($speciesId) {
            $q->whereNull('species_id')->orWhere('species_id', $speciesId);
        });
    }

    /**
     * DURÉE DE CYCLE D'UNE SOUCHE, en jours — ou null si elle n'en déclare pas.
     *
     * LA règle, lue par toutes les portes qui calculent une fin de bande : la
     * bande elle-même (Batch::calculateExpectedEndDate), la planification côté
     * serveur (PlanningController) et l'écran de planification. Une souche sans
     * durée laisse le type de production faire foi, comme avant.
     */
    public static function cycleDaysFor(?string $modelName): ?int
    {
        if (! $modelName) {
            return null;
        }

        $days = static::where('model_name', $modelName)->whereNotNull('cycle_days')->value('cycle_days');

        return $days ? (int) $days : null;
    }

    /**
     * Déduit le slug d'espèce d'un nom de souche par mots-clés.
     * Utilisé pour le backfill (migration) et le rattachement au seed.
     * Retourne null si aucune correspondance fiable (souche générique).
     */
    public static function guessSpeciesSlug(?string $modelName): ?string
    {
        $name = mb_strtolower((string) $modelName);

        // Ordre important : tester les espèces avant les mots ambigus.
        $map = [
            'dinde'   => ['dinde', 'dindon', 'but 6'],
            'caille'  => ['caille'],
            'pintade' => ['pintade'],
            'canard'  => ['canard'],
            'pigeon'  => ['pigeon', 'goliath'],
            'poulet'  => ['poule', 'poulet', 'ross', 'cobb', 'isa', 'lohmann', 'pondeuse', 'cou nu'],
            'mouton'  => ['mouton', 'bélier', 'belier', 'djallonké', 'djallonke'],
            'chevre'  => ['chèvre', 'chevre', 'bouc', 'maradi', 'saanen', 'sahel'],
            'lapin'   => ['lapin'],
            'porc'    => ['porc', 'large white'],
            'tilapia' => ['tilapia', 'alevin'],
            'carpe'   => ['carpe'],
            'silure'  => ['silure', 'clarias'],
            'vache'   => ['vache', 'bovin', 'zébu', 'zebu', 'ndama', 'n\'dama'],
        ];

        foreach ($map as $slug => $keywords) {
            foreach ($keywords as $kw) {
                if ($name !== '' && str_contains($name, $kw)) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /**
     * Liste des types pour l'interface (utilisée dans les selects/filtres)
     */
    public static function types()
    {
        return ['chair', 'ponte', 'repro', 'poussiniere'];
    }

    /**
     * Scope pour filtrer par type
     * Usage : ProductionNorm::byType('chair')->get()
     */
    public function scopeByType($query, $type)
    {
        return $query->where('batch_type', $type);
    }

    /**
     * COURBE DE NORMES D'UNE BANDE, triée par semaine — UNE déclaration, lue par
     * le conseiller, la fiche (poids, ponte), la saisie des œufs et l'analyse HDP.
     *
     * Souche exacte d'abord. À défaut, les normes de l'espèce (ou génériques) du
     * type — mais d'UNE SEULE souche : sans souche, le conseiller mélangeait
     * Ross 308, Cobb 500 et Cou Nu (1 550, 1 520 et 300 g en semaine 4), et la
     * cible dépendait de l'ordre des lignes. On garde la courbe générique si
     * elle existe, sinon la première souche de l'espèce par ordre alphabétique.
     */
    public static function courbePour(Batch $batch): \Illuminate\Support\Collection
    {
        if ($batch->model_name) {
            $parSouche = static::where('model_name', $batch->model_name)->orderBy('week_number')->get();
            if ($parSouche->isNotEmpty()) {
                return $parSouche;
            }
        }

        $candidates = static::where('batch_type', $batch->type)
            ->when($batch->species_id, fn ($q) => $q->forSpecies($batch->species_id))
            ->orderBy('week_number')->get();

        $souches = $candidates->groupBy(fn ($n) => (string) $n->model_name);
        $choisie = $souches->has('') ? '' : $souches->keys()->sort()->first();

        return $choisie === null ? collect() : $souches->get($choisie)->sortBy('week_number')->values();
    }

    /**
     * CIBLES D'UNE BANDE À UNE SEMAINE, interpolées entre les semaines connues.
     *
     * Les courbes ne portent pas toutes les semaines (Cou Nu : 2/4/8/12/16 ;
     * Lohmann Brown : …17/18/20/25…). La recherche à la semaine EXACTE ne
     * trouvait rien entre deux paliers : cible 0, barre de poids verte quel que
     * soit le poids, alerte HDP muette en semaines 21 à 24.
     *
     * @return array{weight: ?float, feed: ?float, water: ?float, laying: ?float, phase: ?string}|null
     */
    public static function cibleA(Batch $batch, ?int $week = null): ?array
    {
        $courbe = static::courbePour($batch);

        return $courbe->isEmpty() ? null : static::interpoler($courbe, $week ?? $batch->semaineDAge());
    }

    /**
     * Interpole linéairement le barème (poids/aliment/eau/ponte) à une semaine.
     * Hors bornes : valeurs de la première / dernière ligne (extrapolation plate).
     *
     * @return array{weight: ?float, feed: ?float, water: ?float, laying: ?float, phase: ?string}
     */
    public static function interpoler(\Illuminate\Support\Collection $curve, int $week): array
    {
        $lower = null;
        $upper = null;

        foreach ($curve as $row) {
            if ($row->week_number <= $week) {
                $lower = $row;
            }
            if ($row->week_number >= $week && $upper === null) {
                $upper = $row;
            }
        }

        // Avant la première semaine connue.
        if ($lower === null) {
            return static::paquet($curve->first());
        }

        // Au-delà de la dernière, ou pile sur un palier.
        if ($upper === null || $lower->week_number === $upper->week_number) {
            return static::paquet($lower);
        }

        $span = $upper->week_number - $lower->week_number;
        $t = $span > 0 ? ($week - $lower->week_number) / $span : 0;

        return [
            'weight' => static::lerp($lower->target_weight, $upper->target_weight, $t),
            'feed'   => static::lerp($lower->target_feed_daily, $upper->target_feed_daily, $t),
            'water'  => static::lerp($lower->target_water_daily, $upper->target_water_daily, $t),
            'laying' => static::lerp($lower->target_laying_rate, $upper->target_laying_rate, $t),
            // Phase = celle du palier dont on est le plus proche.
            'phase'  => $t < 0.5 ? $lower->phase_name : $upper->phase_name,
        ];
    }

    /** @return array{weight: ?float, feed: ?float, water: ?float, laying: ?float, phase: ?string} */
    private static function paquet(self $row): array
    {
        return [
            'weight' => $row->target_weight !== null ? (float) $row->target_weight : null,
            'feed'   => $row->target_feed_daily !== null ? (float) $row->target_feed_daily : null,
            'water'  => $row->target_water_daily !== null ? (float) $row->target_water_daily : null,
            'laying' => $row->target_laying_rate !== null ? (float) $row->target_laying_rate : null,
            'phase'  => $row->phase_name,
        ];
    }

    private static function lerp($a, $b, float $t): ?float
    {
        if ($a === null && $b === null) {
            return null;
        }
        $a = (float) ($a ?? $b);
        $b = (float) ($b ?? $a);

        return $a + ($b - $a) * $t;
    }

    /**
     * Récupère la norme spécifique pour un âge donné (en jours)
     * Calcule automatiquement la semaine correspondante.
     */
    public static function getNormForAge($type, $ageInDays)
    {
        // On s'assure que la semaine commence à 1
        $week = max(1, ceil($ageInDays / 7));
        
        return self::where('batch_type', $type)
                   ->where('week_number', $week)
                   ->first();
    }
}