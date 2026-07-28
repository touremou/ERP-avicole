<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    
    /**
     * PRÉFÉRENCES LIVRÉES PAR DÉFAUT.
     *
     * Elles étaient écrites en dur dans NotificationController::preferences(),
     * dans un `firstOrCreate` — donc la ligne de préférences n'existait QUE si
     * l'utilisateur avait ouvert l'écran des réglages. Et la résolution des
     * destinataires in-app exige une ligne active : un compte qui n'a jamais
     * visité cet écran ne recevait AUCUNE alerte, ni cloche web, ni mobile.
     *
     * Le promoteur en avait donc, ses techniciens non — sans que rien ne le dise.
     */
    public const DEFAULTS = [
        'is_active'        => true,
        'channel_whatsapp' => true,
        'channel_database' => true,   // la cloche : gratuite et non intrusive
        'channel_email'    => false,
        'daily_summary'    => true,
        'alert_mortality'  => true,
        'alert_stock'      => true,
        'alert_energy'     => true,
        'alert_sales'      => false,
        'alert_fraud'      => true,
    ];

    /**
     * Préférences d'un utilisateur, créées au besoin avec les valeurs livrées.
     */
    public static function forUser(int $userId): self
    {
        return static::firstOrCreate(['user_id' => $userId], self::DEFAULTS);
    }

    /**
     * Préférences EFFECTIVES d'un utilisateur, SANS rien écrire : la ligne
     * enregistrée si elle existe, sinon une instance non persistée portant les
     * valeurs livrées. À utiliser dans les chemins de lecture — diffuser une
     * alerte ne doit pas créer de lignes au passage.
     */
    public static function resolveFor(User $user): self
    {
        return $user->notificationPreference
            ?? new static(array_merge(['user_id' => $user->id], self::DEFAULTS));
    }

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
