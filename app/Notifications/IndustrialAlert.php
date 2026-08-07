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

    /**
     * Canaux retenus pour CE destinataire.
     *
     * Le SMS est le canal de repli d'un éleveur dont le WhatsApp ne part pas. Il
     * se paie au message : son interrupteur doit donc être obéi. Or la case
     * « SMS » de Notifications › Préférences (`channel_sms`) était enregistrée et
     * n'était LUE par personne — la décocher n'arrêtait rien, la cocher n'ajoutait
     * rien. Le SMS partait sur la seule condition d'une priorité haute.
     *
     * On consulte désormais la préférence. La PORTÉE d'aujourd'hui est préservée
     * à l'identique : `channel_sms` passe à « activé » par défaut et pour les
     * comptes existants (cf. migration 2026_08_17_000000). Ce lot rend
     * l'interrupteur réel sans rien couper.
     *
     * Ce qui ne change pas, volontairement : le SMS reste réservé aux alertes de
     * priorité HAUTE. L'étendre aux autres familles serait une décision de
     * dépense, pas une correction de défaut.
     */
    public function via($notifiable)
    {
        $channels = ['database'];

        if (($this->alertData['priority'] ?? '') !== 'high') {
            return $channels;
        }

        if ($notifiable instanceof \App\Models\User
            && ! \App\Models\NotificationPreference::resolveFor($notifiable)->channel_sms) {
            return $channels;
        }

        $channels[] = SmsGuineeChannel::class;

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
            // L'utilisateur stocke son mobile dans whatsapp_phone (il n'existe pas
            // de colonne `phone`) — l'ancien $notifiable->phone était toujours null,
            // donc aucun SMS n'atteignait jamais le destinataire.
            'to'      => $notifiable->whatsapp_phone ?? $notifiable->phone ?? null,
            'message' => "AVISMART CRITIQUE: " . ($this->alertData['message'] ?? 'Alerte sans message'),
        ];
    }
}
