<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;

class ProductionAlert extends Notification
{
    use Queueable;

    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function via($notifiable)
    {
        // On envoie en base (In-App) et via SMS si c'est critique
        $channels = ['database'];
        if ($this->data['priority'] === 'high') {
            $channels[] = 'sms'; // Canal personnalisé
        }
        return $channels;
    }

    public function toDatabase($notifiable)
    {
        return [
            'title' => $this->data['title'],
            'message' => $this->data['message'],
            'batch_uuid' => $this->data['batch_uuid'],
            'type' => $notifiable->type,
        ];
    }

    // Logique pour le fournisseur SMS Guinéen
    public function toSms($notifiable)
    {
        return [
            'to' => $notifiable->phone,
            'message' => "AVISMART ALERT: " . $this->data['message'],
        ];
    }
}
