<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmployeeAttendance — pointage de présence quotidien (RH léger).
 *
 * Statuts : present, absent, conge (justifié), retard. Seul « present » et
 * « retard » comptent comme journée travaillée ; « conge » = absence justifiée.
 */
class EmployeeAttendance extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'employee_id', 'attendance_date', 'status',
        'check_in_time', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'attendance_date' => 'date',
    ];

    public const STATUSES = [
        'present' => 'Présent',
        'retard'  => 'Retard',
        'absent'  => 'Absent',
        'conge'   => 'Congé',
    ];

    /** Statuts comptés comme journée travaillée. */
    public const WORKED = ['present', 'retard'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeBetween($query, $from, $to)
    {
        // whereDate() compare la DATE seule : robuste que la colonne stocke
        // « Y-m-d » (MySQL) ou « Y-m-d 00:00:00 » (sqlite via le cast date),
        // sinon la borne haute exclut les enregistrements du jour même.
        return $query->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function getIsWorkedAttribute(): bool
    {
        return in_array($this->status, self::WORKED, true);
    }
}
