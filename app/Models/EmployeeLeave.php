<?php
// ═══════════════════════════════════════════
// app/Models/EmployeeLeave.php
// ═══════════════════════════════════════════

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeave extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'employee_id', 'type', 'start_date', 'end_date',
        'days_count', 'status', 'reason', 'approved_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }

    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'conge_annuel' => 'Congé annuel',
            'maladie'      => 'Maladie',
            'maternite'    => 'Maternité',
            'sans_solde'   => 'Sans solde',
            'absence'      => 'Absence',
            'formation'    => 'Formation',
            default        => 'Autre',
        };
    }
}
