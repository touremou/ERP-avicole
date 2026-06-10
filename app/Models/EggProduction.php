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
        $weights = ['xl' => 73, 'l' => 68, 'm' => 58, 's' => 48]; // Grammes standards
        
        if (!$this->is_graded) return 0.0;

        return (
            ($this->grade_xl * setting('general.eggs_per_tray', 30) * $weights['xl']) +
            ($this->grade_l  * setting('general.eggs_per_tray', 30) * $weights['l'])  +
            ($this->grade_m  * setting('general.eggs_per_tray', 30) * $weights['m'])  +
            ($this->grade_s  * setting('general.eggs_per_tray', 30) * $weights['s'])
        ) / 1000;
    }

    /**
     * Écart de calibrage (Vérification d'intégrité de la table de tri)
     */
    public function getTriDeviationAttribute(): int
    {
        if (!$this->is_graded) return 0;
        
        $totalGradedUnits = ($this->grade_xl + $this->grade_l + $this->grade_m + $this->grade_s) * setting('general.eggs_per_tray', 30);
        return (int) round($this->grade_a_eggs - $totalGradedUnits);
    }

    /**
     * Volume d'alvéoles prêtes à la vente
     */
    public function getSaleableTraysAttribute(): float
    {
        if ($this->is_graded) {
            return (float) ($this->grade_xl + $this->grade_l + $this->grade_m + $this->grade_s);
        }
        return round($this->grade_a_eggs / setting('general.eggs_per_tray', 30), 2);
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
                     ->whereHas('batch', fn($q) => $q->where('status', 'Actif'));
    }

    public function getMapForStockSync(): array
    {
        return [
            'XL'      => (float) $this->grade_xl,
            'L'       => (float) $this->grade_l,
            'M'       => (float) $this->grade_m,
            'S'       => (float) $this->grade_s,
            'Cassé'   => (float) ($this->broken_eggs / 30),
            'Anomalie'=> (float) ($this->small_eggs / 30),
        ];
    }
}