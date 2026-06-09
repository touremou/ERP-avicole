<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPeriod extends Model
{
    use BelongsToFarm;

    protected $fillable = [
        'farm_id', 'label', 'year', 'month', 'start_date', 'end_date',
        'status', 'total_brut', 'total_net', 'total_primes', 'total_deductions',
        'validated_by', 'validated_at',
    ];

    protected $casts = [
        'start_date'   => 'date',
        'end_date'     => 'date',
        'validated_at' => 'datetime',
    ];

    public function payslips(): HasMany { return $this->hasMany(Payslip::class); }
    public function validator(): BelongsTo { return $this->belongsTo(User::class, 'validated_by'); }

    /**
     * Recalcule les totaux depuis les fiches.
     */
    public function recalculateTotals(): void
    {
        $this->update([
            'total_brut'       => $this->payslips()->sum('base_salary'),
            'total_net'        => $this->payslips()->sum('net_salary'),
            'total_primes'     => $this->payslips()->sum('total_primes'),
            'total_deductions' => $this->payslips()->sum('total_deductions'),
        ]);
    }

    public function getEmployeePaidCountAttribute(): int
    {
        return $this->payslips()->where('payment_status', 'paye')->count();
    }
}
