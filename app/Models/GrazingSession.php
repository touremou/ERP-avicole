<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * SORTIE AU PÂTURAGE — un lot de ruminants qui se nourrit lui-même, dehors.
 *
 * Pendant de l'alimentation brute (RawFeeding) : là, l'ouvrier APPORTE la
 * ressource ; ici, les animaux vont la chercher. Ni stock ni quantité : une
 * durée, un lieu, un mode (gardé par un berger, ou laissé en autonomie) et un
 * COMPTAGE au départ et au retour — le contrôle qui compte vraiment dehors.
 *
 * Le temps du berger (toute la sortie s'il garde, la mise dehors et le retour
 * s'ils sont en autonomie) est imputé au lot, comme celui de l'alimentation
 * brute. Une seule porte d'écriture : App\Actions\Batch\RecordGrazing.
 */
class GrazingSession extends Model
{
    use BelongsToFarm, HasStandardUuid;

    /** Gardé par un berger pendant toute la sortie. */
    public const MODE_GARDE = 'garde';

    /** Laissé en autonomie : on sort les bêtes, on les ramène. */
    public const MODE_AUTONOMIE = 'autonomie';

    public const MODES = [
        self::MODE_GARDE     => 'Gardé (berger)',
        self::MODE_AUTONOMIE => 'En autonomie',
    ];

    /** Familles qui pâturent (Species::$family). */
    public const FAMILLES = ['petit_ruminant', 'grand_ruminant'];

    protected $fillable = [
        'uuid', 'farm_id', 'batch_id', 'user_id', 'employee_id', 'plot_id', 'zone',
        'left_at', 'returned_at', 'duration_minutes', 'mode', 'heads_out', 'heads_back',
        'herder_minutes', 'hourly_cost_gnf', 'labor_cost_gnf', 'notes',
    ];

    protected $casts = [
        'left_at'          => 'datetime',
        'returned_at'      => 'datetime',
        'duration_minutes' => 'integer',
        'heads_out'        => 'integer',
        'heads_back'       => 'integer',
        'herder_minutes'   => 'integer',
        'hourly_cost_gnf'  => 'decimal:2',
        'labor_cost_gnf'   => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope(\App\Scopes\FarmScope::class);
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class)->withoutGlobalScope(\App\Scopes\FarmScope::class);
    }

    public function allocation(): MorphOne
    {
        return $this->morphOne(BatchCostAllocation::class, 'source');
    }

    /** Têtes qui ne sont pas revenues (0 si toutes rentrées). */
    public function getManquantsAttribute(): int
    {
        return max(0, (int) $this->heads_out - (int) $this->heads_back);
    }

    /** Où : la parcelle de la ferme, sinon la zone décrite. */
    public function getLieuAttribute(): string
    {
        return $this->plot?->name ?? ($this->zone ?: __('Parcours libre'));
    }
}
