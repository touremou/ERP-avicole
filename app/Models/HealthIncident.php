<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;

class HealthIncident extends Model
{
    use BelongsToFarm;
    protected $fillable = [
        'farm_id', 'building_id', 'user_id', 'incident_date', 'mortality_count',
        'symptoms', 'photo_path', 'status', 'suspected_disease', 'vet_prescription'
    ];

    protected $casts = [
        'incident_date' => 'date',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }
    // 💡 LA CORRECTION EST ICI : Relation avec l'utilisateur (l'agent)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}