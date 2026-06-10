<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Collecte de lait quotidienne d'un lot (laiterie caprine).
 *
 * total_liters est dénormalisé (matin + soir) et recalculé à chaque
 * sauvegarde. unit_price est un snapshot du prix GNF/litre au jour de la
 * collecte (le cours est volatil) → la valorisation reste fidèle.
 */
class MilkProduction extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'batch_id', 'recorded_by',
        'production_date', 'morning_liters', 'evening_liters',
        'total_liters', 'unit_price', 'milking_females', 'notes',
    ];

    protected $casts = [
        'production_date' => 'date',
        'morning_liters'  => 'decimal:2',
        'evening_liters'  => 'decimal:2',
        'total_liters'    => 'decimal:2',
        'unit_price'      => 'decimal:2',
        'milking_females' => 'integer',
    ];

    protected static function booted(): void
    {
        // Maintient total_liters cohérent (matin + soir).
        static::saving(function (MilkProduction $milk) {
            $milk->total_liters = (float) $milk->morning_liters + (float) $milk->evening_liters;
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Valorisation de la collecte (litres × prix au jour J). */
    public function getTotalValueAttribute(): float
    {
        return (float) $this->total_liters * (float) $this->unit_price;
    }

    /** Rendement par femelle traite (litres/tête), si renseigné. */
    public function getYieldPerFemaleAttribute(): ?float
    {
        if (! $this->milking_females) {
            return null;
        }
        return round((float) $this->total_liters / $this->milking_females, 2);
    }
}
