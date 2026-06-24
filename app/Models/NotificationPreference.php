<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    
    protected $fillable = [
        'user_id', 'is_active',
        'channel_whatsapp', 'channel_database', 'channel_email', 'channel_sms',
        'daily_summary', 'alert_mortality', 'alert_stock',
        'alert_energy', 'alert_sales', 'alert_fraud',
        'quiet_start', 'quiet_end',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'channel_whatsapp'  => 'boolean',
        'channel_database'  => 'boolean',
        'channel_email'     => 'boolean',
        'channel_sms'       => 'boolean',
        'daily_summary'     => 'boolean',
        'alert_mortality'   => 'boolean',
        'alert_stock'       => 'boolean',
        'alert_energy'      => 'boolean',
        'alert_sales'       => 'boolean',
        'alert_fraud'       => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Vérifie si l'utilisateur veut recevoir ce type de notification.
     */
    public function wantsNotification(string $type): bool
    {
        if (! $this->is_active) return false;

        return match ($type) {
            'daily_summary'   => $this->daily_summary,
            'mortality_spike' => $this->alert_mortality,
            'stock_critical'  => $this->alert_stock,
            'fuel_low', 'maintenance_due', 'water_low' => $this->alert_energy,
            'sale_created', 'payment_received' => $this->alert_sales,
            'fraud_alert'     => $this->alert_fraud,
            default           => true,
        };
    }

    /**
     * Vérifie si on est dans les heures silencieuses.
     */
    public function isQuietHour(): bool
    {
        $now = now()->format('H:i');
        $start = $this->quiet_start ?? '22:00';
        $end = $this->quiet_end ?? '06:00';

        if ($start > $end) {
            // Plage nocturne (ex: 22:00 → 06:00)
            return $now >= $start || $now < $end;
        }
        return $now >= $start && $now < $end;
    }
}
