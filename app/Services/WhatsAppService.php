<?php

namespace App\Services;

use App\Models\NotificationLog;
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
     * @return array{0: bool, 1: array|null} [succès, réponse provider/erreur]
     */
    private function attemptDelivery(string $phone, string $message): array
    {
        try {
            $result = match ($this->driver) {
                'callmebot' => $this->sendViaCallMeBot($phone, $message),
                'ultramsg'  => $this->sendViaUltraMsg($phone, $message),
                'wati'      => $this->sendViaWati($phone, $message),
                'twilio'    => $this->sendViaTwilio($phone, $message),
                default     => $this->sendViaLog($phone, $message),
            };

            return [$result, null];
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

    // ─────────────────────────────────────────────
    // DRIVERS
    // ─────────────────────────────────────────────

    /**
     * Driver LOG — développement (écrit dans storage/logs/whatsapp.log)
     */
    private function sendViaLog(string $phone, string $message): bool
    {
        Log::channel('single')->info("📱 WhatsApp → {$phone}\n{$message}");
        return true;
    }

    /**
     * Driver CALLMEBOT — gratuit, idéal pour tester.
     *
     * Setup : Envoyer "I allow callmebot to send me messages" au +34 644 51 95 23
     * Récupérer l'apikey dans la réponse, mettre dans WHATSAPP_API_KEY
     *
     * @see https://www.callmebot.com/blog/free-api-whatsapp-messages/
     */
    private function sendViaCallMeBot(string $phone, string $message): bool
    {
        $response = Http::timeout(15)->get('https://api.callmebot.com/whatsapp.php', [
            'phone'  => $phone,
            'text'   => $message,
            'apikey' => $this->apiKey,
        ]);

        return $response->successful();
    }

    /**
     * Driver ULTRAMSG — API REST simple, ~10$/mois.
     *
     * @see https://ultramsg.com/
     */
    private function sendViaUltraMsg(string $phone, string $message): bool
    {
        $baseUrl = $this->apiUrl ?: "https://api.ultramsg.com/{$this->instanceId}";

        $response = Http::timeout(15)
            ->asForm()
            ->post(rtrim($baseUrl, '/') . '/messages/chat', [
                'token' => $this->apiKey,
                'to'    => $phone,
                'body'  => $message,
            ]);

        return $response->successful() && ($response->json('sent') === 'true' || $response->json('sent') === true);
    }

    /**
     * Driver WATI — WhatsApp Business API officiel.
     *
     * @see https://docs.wati.io/
     */
    private function sendViaWati(string $phone, string $message): bool
    {
        $baseUrl = $this->apiUrl ?: config('whatsapp.wati_url', 'https://live-server-1.wati.io');

        $response = Http::timeout(15)
            ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->post(rtrim($baseUrl, '/') . "/api/v1/sendSessionMessage/{$phone}", [
                'messageText' => $message,
            ]);

        return $response->successful();
    }

    /**
     * Driver TWILIO — Enterprise.
     *
     * @see https://www.twilio.com/docs/whatsapp
     */
    private function sendViaTwilio(string $phone, string $message): bool
    {
        $sid = config('whatsapp.twilio_sid');
        $token = config('whatsapp.twilio_token');
        $from = config('whatsapp.twilio_from', 'whatsapp:+14155238886');

        $response = Http::timeout(15)
            ->withBasicAuth($sid, $token)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To'   => "whatsapp:{$phone}",
                'From' => $from,
                'Body' => $message,
            ]);

        return $response->successful();
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
