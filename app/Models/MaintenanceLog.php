<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\BelongsToFarm;

class MaintenanceLog extends Model
{
    use HasFactory, BelongsToFarm;

    protected $table = 'maintenance_logs';

    protected $fillable = [
        'farm_id',
        'mill_machine_id',
        'user_id',
        'hours_at_maintenance',
        'description',
    ];

    protected $casts = [
        'hours_at_maintenance' => 'float',
        'created_at'           => 'datetime', 
        'updated_at'           => 'datetime', 
    ];

    // -----------------------
    // RELATIONS
    // -----------------------

    public function machine(): BelongsTo
    {
        return $this->belongsTo(MillMachine::class, 'mill_machine_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // -----------------------
    // ACCESSEURS
    // -----------------------

    public function getDateDisplayAttribute()
    {
        // Fallback propre
        return $this->created_at;
    }
}