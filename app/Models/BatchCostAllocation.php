<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * IMPUTATION ANALYTIQUE AU LOT — une charge qui n'est PAS une sortie d'argent.
 *
 * La main-d'œuvre d'un lot est déjà payée par la paie : l'enregistrer en
 * dépense (`expenses`, qui alimente la trésorerie) la compterait deux fois.
 * Elle est ici REPARTIE sur le lot, pour son coût de revient
 * (Batch::operating_cost), sans toucher à la trésorerie.
 *
 * Une imputation garde sa SOURCE (ex. la distribution d'herbe qui l'a produite) :
 * idempotente, elle ne se crée qu'une fois par source et par catégorie.
 */
class BatchCostAllocation extends Model
{
    use BelongsToFarm;

    /** Main-d'œuvre de l'alimentation locale (coupe, distribution d'herbe…). */
    public const MAIN_OEUVRE_ALIMENTATION = 'main_oeuvre_alimentation';

    public const CATEGORIES = [
        self::MAIN_OEUVRE_ALIMENTATION => "Coût main-d'œuvre (alimentation locale)",
    ];

    protected $fillable = ['farm_id', 'batch_id', 'category', 'label', 'amount', 'allocated_on', 'source_type', 'source_id'];

    protected $casts = ['amount' => 'decimal:2', 'allocated_on' => 'date'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
