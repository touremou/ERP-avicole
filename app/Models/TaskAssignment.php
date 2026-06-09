<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAssignment extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'task_template_id', 'employee_id', 'title', 'description',
        'category', 'building_id', 'batch_id', 'scheduled_date', 'scheduled_time',
        'duration_minutes', 'priority', 'status', 'started_at', 'completed_at',
        'completed_by', 'completion_notes', 'is_auto_generated',
    ];

    protected $casts = [
        'scheduled_date'   => 'date',
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
        'is_auto_generated' => 'boolean',
    ];

    public function template(): BelongsTo { return $this->belongsTo(TaskTemplate::class, 'task_template_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function building(): BelongsTo { return $this->belongsTo(Building::class); }
    public function batch(): BelongsTo { return $this->belongsTo(Batch::class); }
    public function completedByUser(): BelongsTo { return $this->belongsTo(User::class, 'completed_by'); }

    // Scopes
    public function scopeForDate($q, $date) { return $q->where('scheduled_date', $date); }
    public function scopePending($q) { return $q->whereIn('status', ['a_faire', 'en_retard']); }
    public function scopeCompleted($q) { return $q->where('status', 'fait'); }
    public function scopeOverdue($q) { return $q->where('status', 'a_faire')->where('scheduled_date', '<', now()->toDateString()); }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'a_faire' && $this->scheduled_date->lt(now()->startOfDay());
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'a_faire'   => '⏳ À faire',
            'en_cours'  => '🔄 En cours',
            'fait'      => '✅ Fait',
            'annule'    => '❌ Annulé',
            'en_retard' => '🔴 En retard',
            default     => $this->status,
        };
    }
}
