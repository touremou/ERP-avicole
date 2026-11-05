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
        'farm_id', 'uuid', 'batch_id', 'recorded_by',
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

    /**
     * PEUT-ON CONSIGNER CETTE TRAITE ? — une règle, lue par le bureau et le
     * terrain.
     *
     *   • un lot laitier (Batch::tracksMilk) ;
     *   • la vie du lot (Batch::refusDeSaisie), celle du pointage, de la
     *     collecte et des soins : lot actif, ni avant l'arrivée ni dans le
     *     futur. Aucune des deux portes ne la posait — une traite passait sur
     *     un lot soldé ou clôturé, ou datée d'avant l'arrivée du troupeau ;
     *   • au moins une traite (matin ou soir) : le terrain l'exigeait, le
     *     bureau enregistrait une fiche à zéro litre.
     *
     * @return array<string, string>  vide si la traite est recevable
     */
    public static function refusDeTraite(?Batch $batch, array $saisie): array
    {
        if (! $batch || ! $batch->tracksMilk()) {
            return ['batch_id' => __("Le lot :code n'est pas un lot laitier.", ['code' => $batch?->code ?? ($saisie['batch_id'] ?? '?')])];
        }

        if ($refus = $batch->refusDeSaisie($saisie['production_date'] ?? null, 'production_date')) {
            return $refus;
        }

        if ((float) ($saisie['morning_liters'] ?? 0) + (float) ($saisie['evening_liters'] ?? 0) <= 0) {
            return ['morning_liters' => __('Renseignez au moins une traite (matin ou soir).')];
        }

        return [];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class)->withTrashed();
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
