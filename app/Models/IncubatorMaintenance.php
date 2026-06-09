<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\BelongsToFarm;

class IncubatorMaintenance extends Model
{
    use HasFactory, BelongsToFarm;

    protected $fillable = [
        'farm_id',
        'incubator_id', 
        'maintenance_date', 
        'type', 
        'description', 
        'performed_by'
    ];
    
    protected $casts = [
        'maintenance_date' => 'date',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    /**
     * RELATION : La machine liée à cette intervention
     */
    public function incubator(): BelongsTo
    {
        return $this->belongsTo(Incubator::class)->withTrashed();
    }
}