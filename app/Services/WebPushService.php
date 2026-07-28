<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * PUSH NAVIGATEUR — faire sonner le téléphone même application fermée.
 *
 * Jusqu'ici, une alerte n'atteignait le terrain que si quelqu'un OUVRAIT
 * l'application : la cloche web et le centre d'alertes mobile sont des écrans, pas
 * des sonneries. Une mortalité critique attendait donc la prochaine ouverture —
 * ce qui, pour un promoteur à l'étranger, revient à ne pas être alerté.
 *
 * CE QUI EST ENVOYÉ. Le titre, un message court et l'URL à ouvrir. Rien de
 * sensible : le contenu traverse le serveur de push du fabricant (Google, Apple,
 * Mozilla), certes chiffré de bout en bout, mais un identifiant de lot suffit à
 * dire ce qu'il faut sans exposer de chiffres d'exploitation.
 *
 * CE QUI N'EST PAS GARANTI. Le push n'est pas un canal fiable : un téléphone
 * éteint, un navigateur désinstallé, un abonnement expiré. Il vient EN PLUS de la
 * cloche et du centre d'alertes, qui restent la source de vérité consultable.
 *
 * SUR IPHONE, il faut que l'application ait été AJOUTÉE À L'ÉCRAN D'ACCUEIL
 * (iOS 16.4+) : Safari ne délivre pas de push à un simple onglet. Sur Android
 * Chrome, l'onglet suffit.
 */
class WebPushService
{
    /** Le push est-il configuré (paire de clefs VAPID présente) ? */
    public function isConfigured(): bool
    {
        return $this->publicKey() !== '' && $this->privateKey() !== '';
    }

    public function publicKey(): string
    {
        return (string) setting('push.vapid_public_key', '');
    }

    private function privateKey(): string
    {
        return (string) setting('push.vapid_private_key', '');
    }

    /**
     * Sujet VAPID : une adresse de contact que le fabricant peut joindre si nos
     * envois posent problème. Un « mailto: » suffit et évite d'être filtré.
     */
    private function subject(): string
    {
        $email = (string) setting('whatsapp.admin_email', '');

        return $email !== '' ? "mailto:{$email}" : (string) config('app.url');
    }

    /**
     * Génère une paire de clefs VAPID et l'enregistre au paramétrage.
     *
     * À n'exécuter QU'UNE FOIS : changer la clef publique invalide tous les
     * abonnements existants, chaque appareil devant se réabonner. La méthode
     * refuse donc d'écraser une paire en place sans demande explicite.
     */
    public function generateKeys(bool $force = false): array
    {
        if ($this->isConfigured() && ! $force) {
            throw new \RuntimeException(
                'Une paire de clefs VAPID existe déjà. La remplacer invaliderait '
                . 'tous les abonnements : chaque appareil devrait se réabonner.'
            );
        }

        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();

        \App\Models\Setting::set('push.vapid_public_key', $keys['publicKey']);
        \App\Models\Setting::set('push.vapid_private_key', $keys['privateKey']);
        \App\Models\Setting::clearCache();

        return $keys;
    }

    /**
     * Envoie une notification à tous les appareils d'un utilisateur.
     *
     * Les abonnements que le fournisseur déclare morts (404 / 410) sont SUPPRIMÉS :
     * un endpoint révoqué ne redeviendra jamais valide, et le garder ferait échouer
     * chaque envoi suivant. Les autres échecs incrémentent un compteur — au-delà du
     * seuil, l'appareil est considéré perdu.
     *
     * @return array{sent: int, pruned: int, failed: int}
     */
    public function sendToUser(User $user, array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['sent' => 0, 'pruned' => 0, 'failed' => 0];
        }

        $subscriptions = PushSubscription::where('user_id', $user->id)->get();

        if ($subscriptions->isEmpty()) {
            return ['sent' => 0, 'pruned' => 0, 'failed' => 0];
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => $this->subject(),
                    'publicKey'  => $this->publicKey(),
                    'privateKey' => $this->privateKey(),
                ],
            ]);
            // Time To Live : au-delà, le fournisseur abandonne. Une alerte de ferme
            // n'a plus d'intérêt le lendemain.
            $webPush->setDefaultOptions(['TTL' => 6 * 3600, 'urgency' => 'high']);
        } catch (\Throwable $e) {
            Log::warning("Push : configuration VAPID invalide — {$e->getMessage()}");

            return ['sent' => 0, 'pruned' => 0, 'failed' => $subscriptions->count()];
        }

        $byHash = [];

        foreach ($subscriptions as $subscription) {
            $byHash[$subscription->endpoint_hash] = $subscription;

            try {
                $webPush->queueNotification(
                    Subscription::create($subscription->toPayload()),
                    json_encode($payload, JSON_UNESCAPED_UNICODE)
                );
            } catch (\Throwable $e) {
                Log::warning("Push : abonnement #{$subscription->id} illisible — {$e->getMessage()}");
            }
        }

        $sent = 0;
        $pruned = 0;
        $failed = 0;

        foreach ($webPush->flush() as $report) {
            $hash = PushSubscription::hashFor($report->getEndpoint());
            $subscription = $byHash[$hash] ?? null;

            if ($report->isSuccess()) {
                $sent++;
                $subscription?->update(['last_success_at' => now(), 'failure_count' => 0]);
                continue;
            }

            // 404 / 410 : l'abonnement est révoqué DÉFINITIVEMENT (app désinstallée,
            // notifications refusées). Le garder ferait échouer tous les envois.
            if ($report->isSubscriptionExpired()) {
                $subscription?->delete();
                $pruned++;
                continue;
            }

            $failed++;

            if ($subscription) {
                $subscription->increment('failure_count');

                if ($subscription->failure_count >= PushSubscription::MAX_FAILURES) {
                    $subscription->delete();
                    $pruned++;
                }
            }

            Log::info("Push : échec vers un appareil — {$report->getReason()}");
        }

        return ['sent' => $sent, 'pruned' => $pruned, 'failed' => $failed];
    }
}
