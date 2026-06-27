<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToFarm;
use App\Traits\AuditsChanges;

class Payment extends Model
{
    use BelongsToFarm, AuditsChanges;
    protected $fillable = [
        'farm_id', 'sale_id', 'amount', 'payment_date',
        'method', 'reference', 'received_by', 'notes',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function getMethodLabelAttribute(): string
    {
        return match ($this->method) {
            'especes'      => 'Espèces',
            'orange_money' => 'Orange Money',
            'virement'     => 'Virement bancaire',
            'cheque'       => 'Chèque',
            default        => $this->method,
        };
    }
}
