<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use App\Notifications\Channels\SmsGuineeChannel;

/**
 * IndustrialAlert — Notification d'alerte industrielle.
 *
 * BUG CORRIGÉ (B-25) :
 * toSms() utilisait $this->data au lieu de $this->alertData → crash fatal
 * quand une alerte haute priorité déclenchait un SMS.
 */
class IndustrialAlert extends Notification
{
    protected $alertData;

    public function __construct($alertData)
    {
        $this->alertData = $alertData;
    }

    public function via($notifiable)
    {
        $channels = ['database'];
        if (($this->alertData['priority'] ?? '') === 'high') {
            $channels[] = SmsGuineeChannel::class;
        }
        return $channels;
    }

    public function toDatabase($notifiable)
    {
        return [
            'type'         => $this->alertData['type'] ?? 'general',
            'title'        => $this->alertData['title'] ?? 'Alerte',
            'message'      => $this->alertData['message'] ?? '',
            'id_reference' => $this->alertData['id_reference'] ?? null,
        ];
    }

    /**
     * B-25 corrigé : $this->data → $this->alertData
     */
    public function toSms($notifiable)
    {
        return [
            'to'      => $notifiable->phone,
            'message' => "AVISMART CRITIQUE: " . ($this->alertData['message'] ?? 'Alerte sans message'),
        ];
    }
}
