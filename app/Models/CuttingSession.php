<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToFarm;

class CuttingSession extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'slaughter_order_id', 'session_date', 'operator_id',
        'total_input_kg', 'total_output_kg', 'loss_kg', 'loss_percent', 'notes',
    ];

    protected $casts = [
        'session_date'    => 'date',
        'total_input_kg'  => 'decimal:2',
        'total_output_kg' => 'decimal:2',
        'loss_kg'         => 'decimal:2',
        'loss_percent'    => 'decimal:2',
    ];

    public function order(): BelongsTo { return $this->belongsTo(SlaughterOrder::class, 'slaughter_order_id'); }
    public function operator(): BelongsTo { return $this->belongsTo(User::class, 'operator_id'); }
    public function products(): HasMany { return $this->hasMany(CutProduct::class); }

    public function recalculateLoss(): void
    {
        $output = $this->products()->sum('quantity_kg');
        $loss = max(0, (float) $this->total_input_kg - $output);
        $percent = $this->total_input_kg > 0 ? round(($loss / $this->total_input_kg) * 100, 2) : 0;

        $this->update([
            'total_output_kg' => $output,
            'loss_kg'         => $loss,
            'loss_percent'    => $percent,
        ]);
    }
}
