<?php
namespace App\Notifications\Channels;

use App\Services\SmsService;
use Illuminate\Notifications\Notification;

/**
 * Canal SMS : délègue à SmsService (passerelle configurable, journalisée et
 * tolérante aux pannes — ne casse jamais la chaîne de notification). La
 * notification doit exposer toSms($notifiable) => ['to' => numéro, 'message' => texte].
 */
class SmsGuineeChannel
{
    public function __construct(private SmsService $sms) {}

    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $data = $notification->toSms($notifiable);

        $this->sms->send(
            (string) ($data['to'] ?? ''),
            (string) ($data['message'] ?? ''),
            ['user_id' => $notifiable->id ?? null, 'type' => 'alert']
        );
    }
}
