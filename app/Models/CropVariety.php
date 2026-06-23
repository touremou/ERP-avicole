<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasStandardUuid;

/**
 * Variété d'une espèce du catalogue (module Production Végétale).
 */
class CropVariety extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid;

    protected $fillable = [
        'uuid', 'is_synced', 'last_sync_at',
        'crop_species_id', 'name', 'cycle_days', 'avg_yield_tha', 'cycle_type', 'notes',
    ];

    protected $casts = [
        'is_synced'     => 'boolean',
        'last_sync_at'  => 'datetime',
        'cycle_days'    => 'integer',
        'avg_yield_tha' => 'decimal:2',
    ];

    public function species(): BelongsTo
    {
        return $this->belongsTo(CropSpecies::class, 'crop_species_id');
    }
}
