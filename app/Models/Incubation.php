<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasStandardUuid; // Trait utilisé sur Batch
use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incubation extends Model
{
    use HasFactory, SoftDeletes, HasStandardUuid, BelongsToFarm;

    protected $fillable = [
        
        'uuid',
        'farm_id',
        'batch_id',
        'incubator_id',
        'code_incubation',
        'start_date',
        'incubation_duration',
        'hatch_date_expected',
        'eggs_count', 
        'fertile_eggs', 
        'hatched_chicks', 
        'status',
        'chicks_dispatched', 'chicks_remaining', // incubation, mirage_fait, clos
    ];

    protected $casts = [
        'start_date'          => 'date',
        'hatch_date_expected' => 'date',
        'incubation_duration' => 'integer',
        'eggs_count'          => 'integer',
        'fertile_eggs'        => 'integer',
        'hatched_chicks'      => 'integer',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    // Accessors virtuels pour les calculs de performance
    protected $appends = ['fertility_rate', 'hatchability_rate', 'progress_days']; 

    // -----------------------
    // RELATIONS
    // -----------------------

    public function batch(): BelongsTo 
    {
        return $this->belongsTo(Batch::class);
    }

    public function incubator(): BelongsTo 
    {
        return $this->belongsTo(Incubator::class)->withTrashed(); // Garde le lien même si la machine est réformée
    }

    public function chickDispatches(): HasMany
    {
    return $this->hasMany(\App\Models\ChickDispatch::class);
    }

    public function getChicksRemainingAttribute(): int
    {
        return max(0, ($this->hatched_chicks ?? 0) - ($this->chicks_dispatched ?? 0));
    }
    // -----------------------
    // ACCESSEURS (KPI PERFORMANCE)
    // -----------------------
    /*
    public function getFertilityRateAttribute(): float 
    {
        if (!$this->eggs_count || is_null($this->fertile_eggs)) return 0.0;
        return round(($this->fertile_eggs / $this->eggs_count) * 100, 1);
    }

    public function getHatchabilityRateAttribute(): float 
    {
        if (!$this->fertile_eggs || is_null($this->hatched_chicks)) return 0.0;
        return round(($this->hatched_chicks / $this->fertile_eggs) * 100, 1);
    }
    */
    // Dans app/Models/Incubation.php

    public function getFertilityRateAttribute(): float
    {
        if ($this->eggs_count <= 0) return 0.0;
        return round(($this->fertile_eggs / $this->eggs_count) * 100, 1);
    }

    public function getHatchabilityRateAttribute(): float
    {
        if ($this->fertile_eggs <= 0) return 0.0;
        return round(($this->hatched_chicks / $this->fertile_eggs) * 100, 1);
    }

    public function getProgressDaysAttribute(): int
    {
        if (!$this->start_date) return 0;
        return (int) $this->start_date->diffInDays(now());
    }

    public function getIsMirageLateAttribute(): bool
    {
        return $this->status === 'incubation' && $this->progress_days >= 10;
    }

    // -----------------------
    // SCOPES
    // -----------------------

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'clos');
    }

    public function scopeLate($query)
    {
        return $query->where('status', '!=', 'clos')
                     ->where('hatch_date_expected', '<', now());
    }
}