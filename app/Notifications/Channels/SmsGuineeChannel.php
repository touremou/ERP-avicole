<?php
namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class SmsGuineeChannel
{
    public function send($notifiable, Notification $notification)
    {
        $data = $notification->toSms($notifiable);

        // Exemple d'appel API vers un fournisseur local (ex: Sirocco ou API Orange)
        return Http::post('https://api.sirocco.gn/v1/sms/send', [
            'api_key' => config('services.sms.key'),
            'to' => $data['to'],
            'text' => $data['message'],
            'sender' => 'AVISMART'
        ]);
    }
}