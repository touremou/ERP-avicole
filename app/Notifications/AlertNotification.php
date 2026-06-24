<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AlertNotification — alerte temps réel générique (in-app + e-mail).
 *
 * Émise par NotificationHub::broadcast() en parallèle du WhatsApp existant.
 * Les canaux sont décidés à la construction (par broadcast(), selon les
 * préférences de l'utilisateur et les heures silencieuses) et portés tels
 * quels par via() — on garde ainsi toute la logique d'éligibilité au même
 * endroit (le hub).
 *
 * ShouldQueue : l'envoi (notamment l'e-mail) passe par la file d'attente
 * (QUEUE_CONNECTION). En dev (sync) c'est immédiat ; en prod un worker
 * draine la file sans bloquer la requête qui a déclenché l'alerte.
 */
class AlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array{type:string,title:string,message:string,severity?:string,url?:string} $payload
     * @param array<int,string> $channels  Canaux retenus (ex: ['database','mail']).
     */
    public function __construct(
        public array $payload,
        public array $channels = ['database'],
    ) {}

    public function via($notifiable): array
    {
        return $this->channels;
    }

    /**
     * Charge utile in-app : lue telle quelle par la cloche du layout
     * (data['title'] / data['message']).
     */
    public function toDatabase($notifiable): array
    {
        return [
            'type'     => $this->payload['type'] ?? 'general',
            'title'    => $this->payload['title'] ?? 'Alerte',
            'message'  => $this->payload['message'] ?? '',
            'severity' => $this->payload['severity'] ?? 'normal',
            'url'      => $this->payload['url'] ?? null,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $severity = strtoupper($this->payload['severity'] ?? 'normal');
        $title    = $this->payload['title'] ?? 'Alerte';

        $mail = (new MailMessage)
            ->subject("[{$severity}] {$title} — AviSmart")
            ->greeting($title)
            ->line($this->payload['message'] ?? '');

        if (! empty($this->payload['url'])) {
            $mail->action(__('Ouvrir dans AviSmart'), $this->payload['url']);
        }

        return $mail->salutation('— AviSmart ERP');
    }
}
