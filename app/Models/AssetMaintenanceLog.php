<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class AssetMaintenanceLog extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'energy_source_id',
        'user_id',
        'maintenance_date',
        'type',
        'description',
        'cost',
        'technician',
        'hours_at_maintenance',
        'next_interval_hours',
        'task_assignment_id',
    ];

    protected $casts = [
        'maintenance_date'    => 'date',
        'cost'                => 'decimal:0',
        'hours_at_maintenance' => 'decimal:2',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(EnergySource::class, 'energy_source_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(TaskAssignment::class, 'task_assignment_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'vidange'     => 'Vidange huile',
            'filtres'     => 'Remplacement filtres',
            'inspection'  => 'Inspection générale',
            'reparation'  => 'Réparation',
            'contrat'     => 'Maintenance contrat',
            default       => ucfirst($this->type),
        };
    }
}
