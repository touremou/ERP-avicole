<?php

namespace App\Services;

use App\Models\NotificationLog;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsAppService — Envoi de messages WhatsApp via différents providers.
 *
 * Configuration dans config/whatsapp.php :
 *   'driver'  => env('WHATSAPP_DRIVER', 'log'),  // log, callmebot, ultramsg, wati, twilio
 *   'api_key' => env('WHATSAPP_API_KEY'),
 *   'instance_id' => env('WHATSAPP_INSTANCE_ID'), // UltraMsg
 *
 * Le paramètre whatsapp.api_url (Paramètres > WhatsApp) permet de
 * surcharger l'URL de base utilisée par les drivers ultramsg/wati (ex :
 * instance auto-hébergée), à défaut la valeur par défaut du driver est
 * utilisée.
 *
 * Drivers disponibles :
 * - log       : écrit dans storage/logs (développement)
 * - callmebot : gratuit, aucune inscription (test rapide)
 * - ultramsg  : ~10$/mois, API REST simple
 * - wati      : WhatsApp Business API officiel
 * - twilio    : enterprise
 */
class WhatsAppService
{
    private string $driver;
    private ?string $apiKey;
    private ?string $instanceId;
    private ?string $apiUrl;

    public function __construct()
    {
        $this->driver = setting('whatsapp.driver', config('services.whatsapp.driver', 'log'));
        $this->apiKey = setting('whatsapp.api_key', config('services.whatsapp.api_key', ''));
        $this->instanceId = setting('whatsapp.instance_id', config('services.whatsapp.instance_id', ''));
        $this->apiUrl = setting('whatsapp.api_url', '') ?: null;
    }

    /**
     * Envoie un message WhatsApp.
     *
     * @param string $phone   Numéro avec indicatif (+224620000000)
     * @param string $message Texte du message (supporte les emojis)
     * @param array  $context Métadonnées pour le log (type, user_id, title)
     * @return bool
     */
    public function send(string $phone, string $message, array $context = []): bool
    {
        $phone = $this->normalizePhone($phone);

        if (! $phone) {
            Log::warning("WhatsAppService: numéro invalide, message non envoyé.");
            return false;
        }

        [$result, $response] = $this->attemptDelivery($phone, $message);

        // Logger le résultat (rejouable par avismart:retry-failed-notifications
        // si échec — coupures réseau fréquentes en zone rurale).
        NotificationLog::create([
            'user_id'           => $context['user_id'] ?? null,
            'channel'           => 'whatsapp',
            'type'              => $context['type'] ?? 'general',
            'title'             => $context['title'] ?? 'WhatsApp',
            'message'           => $message,
            'recipient_phone'   => $phone,
            'status'            => $result ? 'sent' : 'failed',
            'attempts'          => 1,
            'provider_response' => $response,
            'sent_at'           => $result ? now() : null,
        ]);

        return $result;
    }

    /**
     * Rejoue l'envoi d'une notification en échec (commande
     * avismart:retry-failed-notifications). Met à jour le log existant au lieu
     * d'en créer un nouveau.
     */
    public function retry(NotificationLog $log): bool
    {
        if (! $log->recipient_phone) {
            return false;
        }

        [$result, $response] = $this->attemptDelivery($log->recipient_phone, $log->message);

        $log->update([
            'status'            => $result ? 'sent' : 'failed',
            'attempts'          => $log->attempts + 1,
            'provider_response' => $response,
            'sent_at'           => $result ? now() : $log->sent_at,
        ]);

        return $result;
    }

    /**
     * Tente la livraison via le driver configuré.
     *
     * @return array{0: bool, 1: array|null} [succès, détails (status/body ou erreur) pour diagnostic]
     */
    private function attemptDelivery(string $phone, string $message): array
    {
        try {
            return match ($this->driver) {
                'callmebot' => $this->sendViaCallMeBot($phone, $message),
                'ultramsg'  => $this->sendViaUltraMsg($phone, $message),
                'wati'      => $this->sendViaWati($phone, $message),
                'twilio'    => $this->sendViaTwilio($phone, $message),
                default     => $this->sendViaLog($phone, $message),
            };
        } catch (\Throwable $e) {
            Log::error("WhatsAppService [{$this->driver}]: {$e->getMessage()}");

            return [false, ['error' => $e->getMessage()]];
        }
    }

    /**
     * Envoi groupé à plusieurs destinataires.
     */
    public function sendBulk(array $recipients, string $message, array $context = []): int
    {
        $sent = 0;
        foreach ($recipients as $phone) {
            if ($this->send($phone, $message, $context)) {
                $sent++;
            }
            // Petit délai entre les envois (rate limiting)
            usleep(500000); // 500ms
        }
        return $sent;
    }

    /**
     * Client HTTP préconfiguré pour les appels providers.
     *
     * Résout la cause #1 d'échec en environnement local/mutualisé :
     * « cURL error 60: SSL certificate problem: unable to get local issuer
     * certificate ». Sur un PHP sans `curl.cainfo`/`openssl.cafile` (WAMP,
     * hébergement bas de gamme), toute requête HTTPS échoue. On pointe donc
     * cURL vers un bundle CA valide fourni par composer/ca-bundle — la
     * vérification reste ACTIVE (sécurisée) sans dépendre de la config php.ini.
     *
     * Le paramètre whatsapp.verify_ssl permet, en dernier recours et de façon
     * explicite, de désactiver la vérification (déconseillé) pour les
     * environnements où aucun bundle n'est exploitable.
     *
     * Si composer/ca-bundle n'est pas présent dans vendor/ (composer
     * install/update pas encore exécuté côté serveur après mise à jour),
     * on se replie silencieusement sur la vérification SSL par défaut de
     * Guzzle/cURL plutôt que de provoquer une erreur fatale "Class not found".
     */
    private function http(): PendingRequest
    {
        $client = Http::timeout(15);

        $verify = filter_var(setting('whatsapp.verify_ssl', true), FILTER_VALIDATE_BOOLEAN);
        if (! $verify) {
            return $client->withoutVerifying();
        }

        if (class_exists(CaBundle::class)) {
            $caPath = CaBundle::getSystemCaRootBundlePath();
            if (is_string($caPath) && is_file($caPath)) {
                $client = $client->withOptions(['verify' => $caPath]);
            }
        }

        return $client;
    }

    // ─────────────────────────────────────────────
    // DRIVERS
    // ─────────────────────────────────────────────

    /**
     * Driver LOG — développement (écrit dans storage/logs/whatsapp.log)
     */
    private function sendViaLog(string $phone, string $message): array
    {
        Log::channel('single')->info("📱 WhatsApp → {$phone}\n{$message}");
        return [true, null];
    }

    /**
     * Driver CALLMEBOT — gratuit, idéal pour tester.
     *
     * Setup : Envoyer "I allow callmebot to send me messages" au +34 644 51 95 23
     * Récupérer l'apikey dans la réponse, mettre dans WHATSAPP_API_KEY
     *
     * Particularité : CallMeBot répond souvent en HTTP 200 même en cas
     * d'erreur (clé invalide, numéro non enregistré, quota dépassé...), avec
     * un message d'erreur dans le corps de la réponse. On détecte donc aussi
     * ces erreurs via le contenu de la réponse, pas seulement le code HTTP.
     *
     * @see https://www.callmebot.com/blog/free-api-whatsapp-messages/
     */
    private function sendViaCallMeBot(string $phone, string $message): array
    {
        $response = $this->http()->get('https://api.callmebot.com/whatsapp.php', [
            'phone'  => $phone,
            'text'   => $message,
            'apikey' => $this->apiKey,
        ]);

        $body = $response->body();
        $success = $response->successful() && ! str_contains(strtolower($body), 'error');

        return [$success, $this->responseDetails($response)];
    }

    /**
     * Driver ULTRAMSG — API REST simple, ~10$/mois.
     *
     * @see https://ultramsg.com/
     */
    private function sendViaUltraMsg(string $phone, string $message): array
    {
        $baseUrl = $this->apiUrl ?: "https://api.ultramsg.com/{$this->instanceId}";

        $response = $this->http()
            ->asForm()
            ->post(rtrim($baseUrl, '/') . '/messages/chat', [
                'token' => $this->apiKey,
                'to'    => $phone,
                'body'  => $message,
            ]);

        $success = $response->successful() && ($response->json('sent') === 'true' || $response->json('sent') === true);

        return [$success, $this->responseDetails($response)];
    }

    /**
     * Driver WATI — WhatsApp Business API officiel.
     *
     * @see https://docs.wati.io/
     */
    private function sendViaWati(string $phone, string $message): array
    {
        $baseUrl = $this->apiUrl ?: config('whatsapp.wati_url', 'https://live-server-1.wati.io');

        $response = $this->http()
            ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->post(rtrim($baseUrl, '/') . "/api/v1/sendSessionMessage/{$phone}", [
                'messageText' => $message,
            ]);

        return [$response->successful(), $this->responseDetails($response)];
    }

    /**
     * Driver TWILIO — Enterprise.
     *
     * @see https://www.twilio.com/docs/whatsapp
     */
    private function sendViaTwilio(string $phone, string $message): array
    {
        $sid = config('whatsapp.twilio_sid');
        $token = config('whatsapp.twilio_token');
        $from = config('whatsapp.twilio_from', 'whatsapp:+14155238886');

        $response = $this->http()
            ->withBasicAuth($sid, $token)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To'   => "whatsapp:{$phone}",
                'From' => $from,
                'Body' => $message,
            ]);

        return [$response->successful(), $this->responseDetails($response)];
    }

    /**
     * Résumé exploitable de la réponse HTTP pour stockage dans
     * NotificationLog.provider_response (diagnostic depuis Notifications >
     * Historique).
     */
    private function responseDetails(\Illuminate\Http\Client\Response $response): array
    {
        return [
            'status' => $response->status(),
            'body'   => mb_substr(trim($response->body()), 0, 500),
        ];
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

    /**
     * Normalise un numéro de téléphone guinéen.
     * Accepte : +224620000000, 224620000000, 620000000, 0620000000
     */
    private function normalizePhone(string $phone): ?string
    {
        $phone = preg_replace('/[\s\-\.\(\)]/', '', $phone);

        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        if (str_starts_with($phone, '224')) {
            return '+' . $phone;
        }

        if (str_starts_with($phone, '0')) {
            return '+224' . substr($phone, 1);
        }

        if (str_starts_with($phone, '6')) {
            return '+224' . $phone;
        }

        return null;
    }
}
