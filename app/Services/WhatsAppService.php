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

    public function __construct()
    {
        $this->driver = setting('whatsapp.driver', config('services.whatsapp.driver', 'log'));
        $this->apiKey = setting('whatsapp.api_key', config('services.whatsapp.api_key', ''));
        $this->instanceId = setting('whatsapp.instance_id', config('services.whatsapp.instance_id', ''));
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

        try {
            $result = match ($this->driver) {
                'callmebot' => $this->sendViaCallMeBot($phone, $message),
                'ultramsg'  => $this->sendViaUltraMsg($phone, $message),
                'wati'      => $this->sendViaWati($phone, $message),
                'twilio'    => $this->sendViaTwilio($phone, $message),
                default     => $this->sendViaLog($phone, $message),
            };

            // Logger le résultat
            NotificationLog::create([
                'user_id'           => $context['user_id'] ?? null,
                'channel'           => 'whatsapp',
                'type'              => $context['type'] ?? 'general',
                'title'             => $context['title'] ?? 'WhatsApp',
                'message'           => $message,
                'status'            => $result ? 'sent' : 'failed',
                'provider_response' => $context['response'] ?? null,
                'sent_at'           => $result ? now() : null,
            ]);

            return $result;

        } catch (\Throwable $e) {
            Log::error("WhatsAppService [{$this->driver}]: {$e->getMessage()}");

            NotificationLog::create([
                'user_id'           => $context['user_id'] ?? null,
                'channel'           => 'whatsapp',
                'type'              => $context['type'] ?? 'general',
                'title'             => $context['title'] ?? 'WhatsApp',
                'message'           => $message,
                'status'            => 'failed',
                'provider_response' => ['error' => $e->getMessage()],
            ]);

            return false;
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
        $response = Http::timeout(15)
            ->asForm()
            ->post("https://api.ultramsg.com/{$this->instanceId}/messages/chat", [
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
        $baseUrl = config('whatsapp.wati_url', 'https://live-server-1.wati.io');

        $response = Http::timeout(15)
            ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->post("{$baseUrl}/api/v1/sendSessionMessage/{$phone}", [
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
