<?php

// app/Models/ProtocolStep.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProtocolStep extends Model
{
    use HasFactory;
    protected $fillable = [
        'protocol_id', 'day_number', 'action_name', 'type', 'product_suggested', 'method'
    ];

    // 💡 AJOUT INDISPENSABLE POUR LA ROBUSTESSE JSON/JS
    protected $casts = [
        'day_number' => 'integer',
    ];

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }
}