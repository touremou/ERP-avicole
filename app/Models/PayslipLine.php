<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayslipLine extends Model
{
    protected $fillable = ['payslip_id', 'type', 'label', 'amount', 'category'];

    public function payslip(): BelongsTo { return $this->belongsTo(Payslip::class); }
}
