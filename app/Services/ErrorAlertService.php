<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ErrorAlertService — Capture les erreurs critiques et alerte l'admin par WhatsApp.
 *
 * INTÉGRATION dans bootstrap/app.php (Laravel 11+) :
 *
 *   ->withExceptions(function (Exceptions $exceptions) {
 *       $exceptions->reportable(function (Throwable $e) {
 *           \App\Services\ErrorAlertService::handle($e);
 *       });
 *   })
 *
 * Ou dans app/Exceptions/Handler.php (Laravel 10) :
 *
 *   public function register()
 *   {
 *       $this->reportable(function (Throwable $e) {
 *           \App\Services\ErrorAlertService::handle($e);
 *       });
 *   }
 *
 * L'application NE CRASHE PAS — l'utilisateur voit une page d'erreur propre
 * pendant que l'admin reçoit le détail par WhatsApp.
 */
class ErrorAlertService
{
    /**
     * Fréquence maximale d'alertes (évite le spam si boucle d'erreurs).
     * 1 alerte WhatsApp par type d'erreur toutes les 5 minutes.
     */
    private const THROTTLE_MINUTES = 5;

    /**
     * Traite une exception : log + alerte WhatsApp si critique.
     */
    public static function handle(Throwable $e): void
    {
        // Ne pas alerter pour les erreurs HTTP classiques (404, 419, 429)
        $httpCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        $ignoredCodes = [404, 419, 429, 403, 401];

        if (in_array($httpCode, $ignoredCodes)) return;

        // Ne pas alerter pour les erreurs de validation
        if ($e instanceof \Illuminate\Validation\ValidationException) return;

        // Ne pas alerter pour les ModelNotFoundException (404 implicite)
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) return;

        // Throttle : éviter le spam
        $errorKey = 'error_alert_' . md5($e->getFile() . $e->getLine());
        $lastAlerted = cache()->get($errorKey);

        if ($lastAlerted && now()->diffInMinutes($lastAlerted) < self::THROTTLE_MINUTES) {
            return; // Déjà alerté récemment pour cette erreur
        }

        cache()->put($errorKey, now(), self::THROTTLE_MINUTES * 60);

        // Construire le message d'alerte
        $message = self::buildAlertMessage($e);

        // Envoyer par WhatsApp (si le service est configuré)
        try {
            $whatsapp = app(WhatsAppService::class);

            // Envoyer aux admins qui ont activé les alertes
            // Relation correcte : userRole (et non 'role', inexistante — un
            // whereHas('role') lèverait une BadMethodCallException qui sauterait
            // directement au catch, neutralisant même le fallback ci-dessous).
            $admins = User::whereNotNull('whatsapp_phone')
                ->whereHas('userRole', fn($q) => $q->where('name', 'admin'))
                ->get();

            // Fallback : si pas d'admin trouvé, chercher par role_id
            if ($admins->isEmpty()) {
                $adminRoleId = \App\Models\Role::where('name', 'admin')->value('id');
                if ($adminRoleId) {
                    $admins = User::whereNotNull('whatsapp_phone')
                        ->where('role_id', $adminRoleId)
                        ->get();
                }
            }

            foreach ($admins as $admin) {
                $whatsapp->send($admin->whatsapp_phone, $message, [
                    'user_id' => $admin->id,
                    'type'    => 'system_error',
                    'title'   => 'Erreur Système',
                ]);
            }

        } catch (\Throwable $alertError) {
            // Si l'envoi WhatsApp échoue aussi, on log silencieusement
            Log::error("ErrorAlertService: impossible d'envoyer l'alerte WhatsApp — {$alertError->getMessage()}");
        }
    }

    /**
     * Construit le message d'alerte formaté pour WhatsApp.
     */
    private static function buildAlertMessage(Throwable $e): string
    {
        $farmName = config('whatsapp.farm_name', 'AviSmart');
        $url = request()?->fullUrl() ?? 'CLI';
        $user = auth()?->user()?->name ?? 'Anonyme';

        // Raccourcir le chemin du fichier
        $file = str_replace(base_path(), '', $e->getFile());

        $lines = [];
        $lines[] = "🔴 *ERREUR SYSTÈME — {$farmName}*";
        $lines[] = "";
        $lines[] = "⏰ " . now()->format('d/m/Y H:i:s');
        $lines[] = "👤 Utilisateur : {$user}";
        $lines[] = "🌐 URL : {$url}";
        $lines[] = "";
        $lines[] = "❌ *" . class_basename($e) . "*";
        $lines[] = substr($e->getMessage(), 0, 200);
        $lines[] = "";
        $lines[] = "📄 {$file}:{$e->getLine()}";
        $lines[] = "";
        $lines[] = "L'application continue de fonctionner.";
        $lines[] = "Consultez les logs pour le détail complet.";

        return implode("\n", $lines);
    }
}
