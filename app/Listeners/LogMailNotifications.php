<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * JOURNAL DU CANAL E-MAIL.
 *
 * `notification_logs` traçait les envois WhatsApp (`WhatsAppService`) et SMS
 * (`SmsService`), et l'écran « Historique des notifications » affiche
 * envoyés/échoués à partir de cette table. Le canal e-mail, lui, n'écrivait
 * RIEN — ni succès, ni échec, ni tentative.
 *
 * Conséquence : à la question « je ne reçois pas les mails », l'application ne
 * pouvait pas répondre. Impossible de distinguer les trois cas qui se
 * ressemblent depuis le fauteuil du promoteur :
 *
 *   • personne n'a coché la case E-mail (channel_email vaut faux par défaut) —
 *     aucune ligne n'apparaît, car rien n'a jamais été tenté ;
 *   • le SMTP refuse (identifiants, expéditeur non autorisé) — la ligne passe
 *     à « échoué », avec le motif rendu par le serveur ;
 *   • le message est bien parti — la ligne passe à « envoyé ».
 *
 * L'absence de trace était indistinguable d'un échec silencieux. C'est le même
 * défaut que partout ailleurs dans cette base : ne rien recevoir ressemble à
 * « tout va bien ».
 *
 * ─── POURQUOI TROIS ÉVÉNEMENTS ───
 *
 * `sent` et `failed` portent le verdict : le canal mail signale bien ses échecs,
 * motif du serveur compris. La ligne posée à l'ENVOI est le FILET — si un envoi
 * meurt sans qu'aucun des deux ne parte, elle reste « en cours », ce qui se lit
 * comme « tenté, jamais abouti ». Aucun chemin ne peut ne rien laisser.
 */
class LogMailNotifications
{
    /**
     * Lignes ouvertes par `sending`, en attente de leur verdict.
     *
     * Clef : l'identifiant unique que Laravel attribue à chaque notification
     * (`Notification::$id`). `sending` et `sent` appartiennent au même envoi,
     * donc au même processus — que la file soit synchrone ou drainée par un
     * worker.
     *
     * @var array<string, int>
     */
    private static array $ouvertes = [];

    public function sending(NotificationSending $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $payload = (array) ($event->notification->payload ?? []);

        $log = NotificationLog::create([
            'user_id'         => $event->notifiable->id ?? null,
            'channel'         => 'mail',
            'type'            => $payload['type'] ?? 'general',
            'title'           => $payload['title'] ?? 'Notification',
            'message'         => $payload['message'] ?? '',
            'recipient_email' => $this->adresse($event->notifiable),
            'status'          => 'queued',
            'attempts'        => 1,
        ]);

        self::$ouvertes[$this->clef($event->notification)] = $log->id;
    }

    public function sent(NotificationSent $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $this->conclure($event->notification, 'sent');
    }

    public function failed(NotificationFailed $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        /*
         * `data['exception']` porte l'objet Throwable. Sérialisé tel quel, il
         * donne `{}` — et l'écran d'historique, qui lit `provider_response.error`
         * comme pour WhatsApp, n'afficherait rien. On en extrait le MOTIF, qui
         * est la seule chose utile : « authentification refusée », « expéditeur
         * non autorisé »… c'est ce que le promoteur doit lire pour corriger.
         */
        $donnees = $event->data ?? [];
        $erreur  = $donnees['exception'] ?? null;

        $this->conclure($event->notification, 'failed', [
            'error' => $erreur instanceof \Throwable
                ? $erreur->getMessage()
                : (string) ($donnees['error'] ?? 'Échec inconnu'),
        ]);
    }

    /** Referme la ligne ouverte à l'envoi, si elle existe encore. */
    private function conclure(object $notification, string $statut, array $reponse = []): void
    {
        $clef = $this->clef($notification);
        $id   = self::$ouvertes[$clef] ?? null;

        if ($id === null) {
            return;
        }

        unset(self::$ouvertes[$clef]);

        NotificationLog::whereKey($id)->update([
            'status'            => $statut,
            'sent_at'           => $statut === 'sent' ? now() : null,
            'provider_response' => $reponse !== [] ? json_encode($reponse) : null,
            'updated_at'        => now(),
        ]);
    }

    private function clef(object $notification): string
    {
        return (string) ($notification->id ?? spl_object_hash($notification));
    }

    /**
     * Adresse visée — celle que le canal `mail` utiliserait.
     *
     * `routeNotificationFor('mail')` couvre aussi bien un utilisateur qu'une
     * adresse anonyme (`Notification::route('mail', ...)`, employée par le filet
     * admin des alertes critiques), et peut rendre une paire adresse => nom.
     */
    private function adresse(object $notifiable): ?string
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return $notifiable->email ?? null;
        }

        $route = $notifiable->routeNotificationFor('mail');

        if (is_array($route)) {
            $premier = array_key_first($route);

            // ['adresse@ex.com' => 'Nom'] ou ['adresse@ex.com']
            return is_string($premier) ? $premier : (string) reset($route);
        }

        return is_string($route) ? $route : null;
    }
}
