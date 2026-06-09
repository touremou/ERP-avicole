<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class DiscrepancyReport extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'dispatch_id', 'reception_id', 'reported_by',
        'total_dispatched', 'total_received',
        'total_damaged', 'total_missing', 'discrepancy_rate',
        'severity', 'resolution', 'resolution_notes',
        'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'total_dispatched'  => 'decimal:2',
        'total_received'    => 'decimal:2',
        'total_damaged'     => 'decimal:2',
        'total_missing'     => 'decimal:2',
        'discrepancy_rate'  => 'decimal:2',
        'resolved_at'       => 'datetime',
    ];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function getIsResolvedAttribute(): bool
    {
        return in_array($this->resolution, ['justifie', 'injustifie']);
    }

    public function getSeverityColorAttribute(): string
    {
        return match ($this->severity) {
            'critique'  => 'red',
            'attention' => 'amber',
            default     => 'green',
        };
    }
}
