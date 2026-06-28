<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

/**
 * Validation explicite d'une étape d'itinéraire technique pour un cycle donné.
 *
 * Trace QUI a validé QUOI et QUAND. Consommée par CropProtocolAlertService pour
 * marquer l'étape « done » de façon fiable (prioritaire sur l'inférence par nom).
 */
class CropProtocolCompletion extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'crop_cycle_id', 'crop_protocol_item_id',
        'completed_at', 'completed_by', 'notes',
        'cost', 'quantity', 'unit', 'crop_input_id',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'cost'         => 'decimal:2',
        'quantity'     => 'decimal:3',
    ];

    public function cropInput(): BelongsTo
    {
        return $this->belongsTo(CropInput::class, 'crop_input_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class, 'crop_cycle_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CropProtocolItem::class, 'crop_protocol_item_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
