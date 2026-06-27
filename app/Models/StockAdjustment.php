<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StockAdjustment — ajustement de stock formel (démarque / inventaire).
 *
 * Snapshot immuable : motif, avant/après, delta signé, CMP figé et valeur de
 * l'impact. Une perte (delta < 0) chiffrée par la démarque ; un gain (delta > 0)
 * = écart d'inventaire positif. Le mouvement de stock physique est tracé en
 * parallèle par un StockMovement de type « adjustment » (cf. CreateStockAdjustment).
 */
class StockAdjustment extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'stock_id', 'user_id', 'reference', 'type', 'reason',
        'quantity_before', 'quantity_after', 'delta', 'unit_cost', 'value_impact',
        'adjustment_date', 'notes',
    ];

    protected $casts = [
        'quantity_before' => 'decimal:3',
        'quantity_after'  => 'decimal:3',
        'delta'           => 'decimal:3',
        'unit_cost'       => 'decimal:2',
        'value_impact'    => 'decimal:2',
        'adjustment_date' => 'date',
    ];

    /** Motifs d'ajustement (clé stockée => libellé FR). */
    public const REASONS = [
        'inventaire'           => "Écart d'inventaire",
        'casse'                => 'Casse / détérioration',
        'peremption'           => 'Péremption',
        'vol'                  => 'Vol / disparition',
        'don'                  => 'Don / prélèvement',
        'consommation_interne' => 'Consommation interne',
        'correction'           => 'Correction de saisie',
    ];

    // ─── RELATIONS ───

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── SCOPES ───

    public function scopeLosses($query)
    {
        return $query->where('type', 'perte');
    }

    public function scopeGains($query)
    {
        return $query->where('type', 'gain');
    }

    public function scopeBetweenDates($query, $from, $to)
    {
        return $query->whereDate('adjustment_date', '>=', $from)->whereDate('adjustment_date', '<=', $to);
    }

    // ─── ACCESSORS ───

    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? ucfirst(str_replace('_', ' ', (string) $this->reason));
    }

    public function getIsLossAttribute(): bool
    {
        return $this->type === 'perte';
    }
}
