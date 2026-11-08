<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use App\Traits\HasStandardUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * ALIMENTATION BRUTE / SPONTANÉE — la distribution d'une ressource NON
 * formulée : herbe coupée, fanes de maïs, épluchures, feuillages.
 *
 * Ce n'est PAS un mouvement de stock : la quantité est une estimation
 * (« 3 brouettes ») et rien n'est déduit d'un magasin. C'est une preuve de
 * travail — qui, quand, quoi, pour quel lot, combien de temps — et le temps
 * passé, valorisé au coût horaire de l'employé, est imputé au lot
 * (BatchCostAllocation, catégorie main-d'œuvre de l'alimentation locale).
 *
 * Une seule porte d'écriture : App\Actions\Batch\RecordRawFeeding.
 */
class RawFeeding extends Model
{
    use BelongsToFarm, HasStandardUuid;

    /** Ressources proposées sur le terrain — la liste reste OUVERTE. */
    public const RESSOURCES_COURANTES = [
        'Herbe locale', 'Fanes de maïs', "Fanes d'arachide", 'Feuillages',
        'Épluchures', 'Restes de cuisine', 'Son de riz', 'Paille',
    ];

    /** Unités d'estimation sans balance. */
    public const UNITES = ['kg', 'brouette', 'botte', 'sac', 'seau', 'fagot', 'tas'];

    protected $fillable = [
        'uuid', 'farm_id', 'batch_id', 'user_id', 'employee_id', 'plot_id', 'fed_at',
        'resource', 'quantity', 'quantity_unit', 'minutes_spent', 'hourly_cost_gnf', 'labor_cost_gnf', 'notes',
    ];

    protected $casts = [
        'fed_at'          => 'datetime',
        'quantity'        => 'decimal:2',
        'minutes_spent'   => 'integer',
        'hourly_cost_gnf' => 'decimal:2',
        'labor_cost_gnf'  => 'decimal:2',
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
}
