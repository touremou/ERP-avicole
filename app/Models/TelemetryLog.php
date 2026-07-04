<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ZONE TAMPON d'ingestion IoT : l'endpoint API écrit ici et uniquement ici ;
 * le worker `telemetry:process` associe ensuite chaque relevé au lot actif
 * (bâtiment + heure). Aucune écriture directe dans les tables métier.
 */
class TelemetryLog extends Model
{
    public const UPDATED_AT = null; // journal en append-only

    public const STATUS_PENDING = 'pending';
    public const STATUS_LINKED  = 'linked';
    public const STATUS_ORPHAN  = 'orphan';

    protected $fillable = [
        'farm_id', 'sensor_id', 'metric', 'value', 'unit',
        'recorded_at', 'building_id', 'batch_id', 'status',
    ];

    protected $casts = [
        'value'       => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function building(): BelongsTo { return $this->belongsTo(Building::class); }
    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }

    public function scopePending($q) { return $q->where('status', self::STATUS_PENDING); }
}
