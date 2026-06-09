<?php

namespace App\Notifications\Channels;

use App\Models\NotificationPreference;
use App\Services\WhatsAppService;
use Illuminate\Notifications\Notification;

/**
 * Canal WhatsApp pour le système de notifications Laravel.
 *
 * Usage dans une notification :
 *   public function via($notifiable) {
 *       return ['database', WhatsAppChannel::class];
 *   }
 *
 *   public function toWhatsApp($notifiable) {
 *       return [
 *           'message' => "🐔 Alerte mortalité...",
 *           'type'    => 'mortality_spike',
 *       ];
 *   }
 */
class WhatsAppChannel
{
    public function __construct(
        private WhatsAppService $whatsapp
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        // Vérifier que le notifiable a un numéro WhatsApp
        $phone = $notifiable->whatsapp_phone ?? $notifiable->phone ?? null;

        if (! $phone) return;

        // Vérifier les préférences
        $prefs = NotificationPreference::where('user_id', $notifiable->id)->first();

        if ($prefs) {
            if (! $prefs->is_active || ! $prefs->channel_whatsapp) return;

            // Récupérer le type de notification
            $data = $notification->toWhatsApp($notifiable);
            $type = $data['type'] ?? 'general';

            if (! $prefs->wantsNotification($type)) return;

            // Heures silencieuses (sauf alertes critiques)
            $severity = $data['severity'] ?? 'normal';
            if ($prefs->isQuietHour() && $severity !== 'critique') return;
        }

        $data = $notification->toWhatsApp($notifiable);

        $this->whatsapp->send($phone, $data['message'], [
            'user_id' => $notifiable->id,
            'type'    => $data['type'] ?? 'general',
            'title'   => $data['title'] ?? 'Notification',
        ]);
    }
}
