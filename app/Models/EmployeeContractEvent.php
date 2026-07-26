<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décision prise sur un contrat à durée déterminée.
 *
 * `employees.contract_end_date` ne porte que le terme COURANT : à chaque
 * prolongation, l'ancien terme est écrasé. Sans cette trace, on ne saurait plus
 * qu'un CDD a été prolongé trois fois — or c'est exactement ce qu'un contrôle
 * regarde, et ce qui distingue une prolongation régulière d'un CDI de fait.
 */
class EmployeeContractEvent extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'employee_id', 'type', 'decided_on',
        'previous_end_date', 'new_end_date', 'reason', 'user_id',
    ];

    protected $casts = [
        'decided_on'        => 'date',
        'previous_end_date' => 'date',
        'new_end_date'      => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getLabelAttribute(): string
    {
        return match ($this->type) {
            'prolongation' => __('Prolongation'),
            'preavis'      => __('Préavis émis'),
            'fin'          => __('Fin de contrat'),
            default        => $this->type,
        };
    }
}
