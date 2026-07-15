<?php

namespace App\Services;

use App\Models\NotificationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SmsService — envoi de SMS via une passerelle locale (opérateur / gateway GSM).
 *
 * Pendant SMS du WhatsAppService. Driver paramétrable (Réglages › SMS, repli
 * config/services.php) :
 *   - 'log'  : n'envoie rien, journalise (développement / pas de passerelle).
 *   - 'http' : POST application/x-www-form-urlencoded vers sms.api_url.
 *
 * Robuste : ne lève JAMAIS (un canal de notification qui plante ne doit pas
 * casser la chaîne) ; journalise chaque tentative dans notification_logs ;
 * respecte le quota SMS de la licence (consommé seulement sur envoi RÉEL abouti).
 */
class SmsService
{
    /**
     * @return bool true si remis (ou journalisé en mode log), false sinon.
     */
    public function send(string $to, string $message, array $context = []): bool
    {
        $to = trim($to);
        if ($to === '' || trim($message) === '') {
            return false;
        }

        $driver = (string) setting('sms.driver', config('services.sms.driver', 'log'));

        // Mode développement : aucune passerelle, on journalise et on s'arrête.
        if ($driver === 'log') {
            Log::info("SMS [log] → {$to} : {$message}");
            $this->record($to, $message, 'sent', ['driver' => 'log'], $context);
            return true;
        }

        // Quota d'abonnement (le SMS consomme le crédit comme WhatsApp).
        $license = app(LicenseService::class);
        if ($license->smsRemaining() <= 0) {
            Log::warning('SmsService: quota SMS de la licence épuisé.');
            $this->record($to, $message, 'failed', ['error' => 'Quota SMS épuisé (licence)'], $context);
            return false;
        }

        $apiUrl = (string) (setting('sms.api_url', '') ?: config('services.sms.api_url', ''));
        $apiKey = (string) (setting('sms.api_key', '') ?: config('services.sms.key', ''));
        $sender = (string) (setting('sms.sender', '') ?: config('services.sms.sender', 'AVISMART'));

        if ($apiUrl === '') {
            $this->record($to, $message, 'failed', ['error' => 'URL de passerelle SMS non configurée (Réglages › SMS).'], $context);
            return false;
        }

        try {
            $response = Http::timeout(10)->asForm()->post($apiUrl, [
                'api_key' => $apiKey,
                'to'      => $to,
                'text'    => $message,
                'sender'  => $sender,
            ]);

            $ok = $response->successful();
            $this->record($to, $message, $ok ? 'sent' : 'failed', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 500),
            ], $context);

            if ($ok) {
                $license->consumeSms(1);
            }

            return $ok;
        } catch (\Throwable $e) {
            Log::error("SmsService: {$e->getMessage()}");
            $this->record($to, $message, 'failed', ['error' => $e->getMessage()], $context);
            return false;
        }
    }

    /** Journalise la tentative (sans jamais casser l'envoi sur erreur de log). */
    private function record(string $to, string $message, string $status, array $resp, array $context): void
    {
        try {
            NotificationLog::create([
                'user_id'           => $context['user_id'] ?? null,
                'channel'           => 'sms',
                'type'              => $context['type'] ?? 'general',
                'title'             => $context['title'] ?? 'SMS',
                'message'           => $message,
                'recipient_phone'   => $to,
                'status'            => $status,
                'attempts'          => 1,
                'provider_response' => $resp,
                'sent_at'           => $status === 'sent' ? now() : null,
            ]);
        } catch (\Throwable) {
            // Échec de journalisation non bloquant.
        }
    }
}
