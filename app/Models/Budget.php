<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

/**
 * Budget — montant prévisionnel alloué à un poste de dépense pour un mois.
 *
 * Le poste (category) réutilise la taxonomie Expense::CATEGORIES : le suivi
 * rapproche le budget de la somme des dépenses VALIDÉES de cette catégorie.
 */
class Budget extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'category', 'year', 'month', 'amount', 'created_by',
    ];

    protected $casts = [
        'year'   => 'integer',
        'month'  => 'integer',
        'amount' => 'decimal:2',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Budgets d'une période (année + mois). */
    public function scopeForPeriod($query, int $year, int $month)
    {
        return $query->where('year', $year)->where('month', $month);
    }

    public function getCategoryLabelAttribute(): string
    {
        return Expense::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }
}
