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
        // UN RAPPORTEUR D'ERREUR NE DOIT JAMAIS ÉCHOUER.
        //
        // Cette méthode est appelée depuis bootstrap/app.php sur CHAQUE exception
        // rapportable. Si elle lève à son tour, elle détruit l'information qu'elle
        // était censée transmettre : le journal garde la trace du messager, pas du
        // message, et l'alerte de l'incident réel ne part jamais.
        //
        // C'est arrivé en production le 14/07 (cf. le throttle plus bas). Le
        // garde-fou vaut pour toutes les causes, pas seulement celle-là.
        try {
            self::attempt($e);
        } catch (Throwable $inner) {
            // Dernier recours : on n'utilise QUE le journal, jamais le cache ni la
            // base — ce sont eux qui viennent d'échouer, le plus souvent.
            try {
                Log::warning('[ErrorAlertService] Alerte non émise : ' . $inner->getMessage());
            } catch (Throwable) {
                // Même le journal est tombé : on abandonne en silence plutôt que
                // de masquer l'erreur d'origine par la nôtre.
            }
        }
    }

    private static function attempt(Throwable $e): void
    {
        // Jamais pendant les tests (la CI ne doit pas tenter d'envoyer du
        // WhatsApp) ; pilotable par ERROR_ALERTS_ENABLED (défaut : actif
        // hors environnement local). Audit P2-⑪.
        if (app()->runningUnitTests()) return;

        // Coupe-circuit lu depuis la CONFIG, pas depuis env() : le déploiement met
        // la configuration en cache, après quoi le .env n'est plus lu du tout.
        // Renseigné ici, ERROR_ALERTS_ENABLED redevient effectif.
        if (! config('whatsapp.error_alerts_enabled', true)) return;
        if (app()->environment('local')) return;

        // Refus métier propres (machines à états) : pas un incident serveur.
        if ($e instanceof \DomainException) return;

        // Ne pas alerter pour les erreurs HTTP classiques (404, 419, 429)
        $httpCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        $ignoredCodes = [404, 419, 429, 403, 401];

        if (in_array($httpCode, $ignoredCodes)) return;

        // Ne pas alerter pour les erreurs de validation
        if ($e instanceof \Illuminate\Validation\ValidationException) return;

        // Ne pas alerter pour les ModelNotFoundException (404 implicite)
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) return;

        // Throttle : éviter le spam.
        //
        // On mémorise un HORODATAGE ENTIER, jamais un objet. Le cache stockait un
        // Carbon sérialisé ; à la relecture, quand la classe n'est pas
        // reconstructible — ce qui arrive précisément pendant une erreur fatale —
        // PHP rend un __PHP_Incomplete_Class, et `now()->diffInMinutes()` lève une
        // TypeError. Le rapporteur d'erreur échouait donc en rapportant l'erreur.
        //
        // Vu en production le 14/07 : erreur fatale à 07:51:51, TypeError du
        // rapporteur à 07:51:52. L'alerte de l'erreur fatale n'est jamais partie,
        // et le journal a gardé la trace du messager plutôt que du message.
        $errorKey = 'error_alert_' . md5($e->getFile() . $e->getLine());

        $lastAlerted = cache()->get($errorKey);
        $lastAlerted = is_numeric($lastAlerted) ? (int) $lastAlerted : null;

        if ($lastAlerted !== null && (time() - $lastAlerted) < self::THROTTLE_MINUTES * 60) {
            return; // Déjà alerté récemment pour cette erreur
        }

        cache()->put($errorKey, time(), self::THROTTLE_MINUTES * 60);

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
        $farmName = \App\Models\Setting::companyName();
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
