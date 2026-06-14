<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    
    protected $fillable = [
        'user_id', 'channel', 'type', 'title', 'message', 'recipient_phone',
        'status', 'attempts', 'provider_response', 'sent_at',
    ];

    protected $casts = [
        'provider_response' => 'array',
        'sent_at'           => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Notifications WhatsApp en échec, rejouables par
     * avismart:retry-failed-notifications (numéro connu, sous le plafond de
     * tentatives, pas trop anciennes pour éviter de spammer sur de vieux
     * incidents).
     */
    public function scopeRetryable($query, int $maxAttempts = 5, int $maxAgeHours = 24)
    {
        return $query->where('channel', 'whatsapp')
            ->where('status', 'failed')
            ->where('attempts', '<', $maxAttempts)
            ->whereNotNull('recipient_phone')
            ->where('created_at', '>=', now()->subHours($maxAgeHours));
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
}
