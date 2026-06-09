<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToFarm;

class BatchTask extends Model
{
    use BelongsToFarm;
    
    protected $fillable = [
        'farm_id', 'batch_id', 'action_name', 'type', 'method', 
        'day_number', 'planned_date', 'is_completed', 
        'completed_at', 'operator_signature','is_system_generated'
    ];

    protected $casts = [
        'planned_date' => 'date',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime'
    ];

    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }
}