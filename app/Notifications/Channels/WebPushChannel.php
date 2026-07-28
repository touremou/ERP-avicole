<?php

namespace App\Notifications\Channels;

use App\Services\WebPushService;
use Illuminate\Notifications\Notification;

/**
 * Canal « webpush » : la notification part vers les appareils abonnés.
 *
 * Il s'intègre au mécanisme existant plutôt que de le doubler : NotificationHub
 * décide des canaux d'un destinataire (cloche, e-mail, et maintenant push) et
 * AlertNotification les porte. La décision reste donc en UN endroit — c'est
 * exactement ce qui manquait aux règles corrigées tout au long de cet audit.
 */
class WebPushChannel
{
    public function __construct(private WebPushService $push) {}

    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWebPush')) {
            return;
        }

        if (! $notifiable instanceof \App\Models\User) {
            return;
        }

        $this->push->sendToUser($notifiable, $notification->toWebPush($notifiable));
    }
}
