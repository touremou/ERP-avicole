<?php
// ═══════════════════════════════════════════════
// Ce fichier contient 5 models à séparer dans
// des fichiers individuels lors de l'installation
// ═══════════════════════════════════════════════

// ─── 1. SlaughterResult.php ───

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class SlaughterResult extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id',
        'slaughter_order_id', 'total_carcass_weight_kg', 'carcass_yield_percent', 'presentation',
        'condemned_count', 'condemned_reason',
        'avg_live_weight_kg', 'avg_carcass_weight_kg',
        'execution_date', 'inspector_notes',
    ];

    protected $casts = [
        'total_carcass_weight_kg' => 'decimal:2',
        'carcass_yield_percent'   => 'decimal:2',
        'avg_live_weight_kg'      => 'decimal:3',
        'avg_carcass_weight_kg'   => 'decimal:3',
        'execution_date'          => 'date',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SlaughterOrder::class, 'slaughter_order_id');
    }

    public function getYieldStatusAttribute(): string
    {
        if ($this->carcass_yield_percent >= 73) return 'excellent';
        if ($this->carcass_yield_percent >= 70) return 'bon';
        if ($this->carcass_yield_percent >= 65) return 'acceptable';
        return 'faible';
    }
}
