<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'payroll_period_id', 'employee_id',
        'base_salary', 'total_primes', 'total_deductions', 'net_salary',
        'days_worked', 'days_absent', 'days_leave', 'overtime_hours',
        'payment_method', 'payment_reference', 'payment_status', 'paid_at',
        'notes',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function period(): BelongsTo { return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function lines(): HasMany { return $this->hasMany(PayslipLine::class); }

    public function primes(): HasMany { return $this->lines()->where('type', 'prime'); }
    public function deductions(): HasMany { return $this->lines()->where('type', 'deduction'); }

    /**
     * Recalcule le net depuis les lignes.
     */
    public function recalculate(): void
    {
        $primes = (int) $this->lines()->where('type', 'prime')->sum('amount');
        $deductions = (int) $this->lines()->where('type', 'deduction')->sum('amount');

        $this->update([
            'total_primes'     => $primes,
            'total_deductions' => $deductions,
            'net_salary'       => $this->base_salary + $primes - $deductions,
        ]);
    }
}
