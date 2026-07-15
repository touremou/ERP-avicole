<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReminder extends Model
{
    use BelongsToFarm;

    protected $fillable = ['farm_id', 'sale_id', 'client_id', 'user_id', 'channel', 'message', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
