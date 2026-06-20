<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class EggProduction extends Model
{
    use HasFactory, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'batch_id',
        'production_date',
        'total_eggs_collected',
        'broken_eggs',
        'small_eggs',
        'incubable_eggs',
        'grade_xl',
        'grade_l',
        'grade_m',
        'grade_s',
        'laying_rate',
        'observations',
        'is_graded',
        'synced_uuids',
    ];

    protected $casts = [
        // 'date:Y-m-d' force un stockage en 'Y-m-d' (sans heure) : indispensable
        // pour que le cumul journalier (where production_date = 'Y-m-d') matche
        // la ligne du jour. Sans cela, SQLite stocke 'Y-m-d 00:00:00' et chaque
        // passage crée une ligne en double au lieu de cumuler.
        'production_date'      => 'date:Y-m-d',
        'laying_rate'          => 'decimal:2',
        'grade_xl'             => 'decimal:3', // Haute précision pour les alvéoles fractionnées
        'grade_l'              => 'decimal:3',
        'grade_m'              => 'decimal:3',
        'grade_s'              => 'decimal:3',
        'total_eggs_collected' => 'integer',
        'broken_eggs'          => 'integer',
        'small_eggs'           => 'integer',
        'incubable_eggs'       => 'integer',
        'is_graded'            => 'boolean',
        'synced_uuids'         => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    // -----------------------------------------------------------------
    // CALIBRES — SOURCE DE VÉRITÉ UNIQUE
    // -----------------------------------------------------------------
    //
    // Les 4 calibres XL/L/M/S sont le standard avicole de calibrage et sont
    // adossés à des colonnes fixes (grade_xl/l/m/s). Ce catalogue centralise
    // tout ce qui les caractérise (colonne BDD, couleur UI, poids moyen en g)
    // pour qu'aucune liste ne soit codée en dur ailleurs dans l'application.
    //
    // Le paramètre production.egg_grades pilote, lui, QUELS calibres sont
    // actifs et DANS QUEL ORDRE — d'où le fait que ce réglage « s'applique »
    // désormais partout (formulaire de tri, KPI, stock, validations, vues).

    public const GRADE_CATALOG = [
        'XL' => ['column' => 'grade_xl', 'color' => 'blue',   'weight' => 73],
        'L'  => ['column' => 'grade_l',  'color' => 'indigo', 'weight' => 68],
        'M'  => ['column' => 'grade_m',  'color' => 'slate',  'weight' => 58],
        'S'  => ['column' => 'grade_s',  'color' => 'orange', 'weight' => 48],
    ];

    /**
     * Calibres actifs, dans l'ordre défini par le paramètre production.egg_grades.
     * Retourne [CODE => meta]. Toujours un sous-ensemble validé du catalogue
     * canonique : une valeur de paramètre erronée est ignorée sans casse.
     */
    public static function activeGrades(): array
    {
        $configured = collect(explode(',', (string) setting('production.egg_grades', 'XL,L,M,S')))
            ->map(fn ($g) => strtoupper(trim($g)))
            ->filter(fn ($g) => isset(self::GRADE_CATALOG[$g]))
            ->unique()
            ->values();

        if ($configured->isEmpty()) {
            $configured = collect(array_keys(self::GRADE_CATALOG));
        }

        return $configured->mapWithKeys(fn ($g) => [$g => self::GRADE_CATALOG[$g]])->all();
    }

    /** Codes des calibres actifs (ex: ['XL','L','M','S']). */
    public static function gradeCodes(): array
    {
        return array_keys(self::activeGrades());
    }

    // -----------------------
    // KPIS TECHNIQUES AVICOLES
    // -----------------------

    /**
     * Œufs commercialisables nets (Total collecté - Rebus)
     */
    public function getGradeAEggsAttribute(): int
    {
        $net = $this->total_eggs_collected - ($this->broken_eggs + $this->small_eggs);
        return (int) max($net, 0);
    }

    /**
     * Masse totale estimée en Kg (Indicateur FCR de performance alimentaire)
     */
    public function getEstimatedEggMassAttribute(): float
    {
        if (!$this->is_graded) return 0.0;

        $perTray = setting('general.eggs_per_tray', 30);
        $grams = 0;

        foreach (self::activeGrades() as $meta) {
            $grams += (float) $this->{$meta['column']} * $perTray * $meta['weight'];
        }

        return $grams / 1000;
    }

    /**
     * Écart de calibrage (Vérification d'intégrité de la table de tri)
     */
    public function getTriDeviationAttribute(): int
    {
        if (!$this->is_graded) return 0;

        $totalGradedUnits = $this->totalGradedTrays() * setting('general.eggs_per_tray', 30);
        return (int) round($this->grade_a_eggs - $totalGradedUnits);
    }

    /**
     * Volume d'alvéoles prêtes à la vente
     */
    public function getSaleableTraysAttribute(): float
    {
        if ($this->is_graded) {
            return $this->totalGradedTrays();
        }
        return round($this->grade_a_eggs / setting('general.eggs_per_tray', 30), 2);
    }

    /** Somme des alvéoles triées sur l'ensemble des calibres actifs. */
    public function totalGradedTrays(): float
    {
        $trays = 0;
        foreach (self::activeGrades() as $meta) {
            $trays += (float) $this->{$meta['column']};
        }
        return $trays;
    }

    // -----------------------
    // SCOPES TECHNIQUES AUDITÉS
    // -----------------------

    public function scopeToday($query)
    {
        return $query->whereDate('production_date', now()->toDateString());
    }

    public function scopeNeedsGrading($query)
    {
        return $query->where('is_graded', false);
    }

    /**
     * Rigueur O-01 : Isole le stock non trié uniquement sur les lots industriels actifs
     */
    public function scopeUngradedActive($query)
    {
        return $query->where('is_graded', false)
                     ->whereHas('batch', fn($q) => $q->active());
    }

    public function getMapForStockSync(): array
    {
        $map = [];
        foreach (self::activeGrades() as $code => $meta) {
            $map[$code] = (float) $this->{$meta['column']};
        }

        $perTray = setting('general.eggs_per_tray', 30);
        $map['Cassé']    = (float) ($this->broken_eggs / $perTray);
        $map['Anomalie'] = (float) ($this->small_eggs / $perTray);

        return $map;
    }
}