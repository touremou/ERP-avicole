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
        /*
         * IL N'Y A PLUS D'ARRÊT GLOBAL EN TEST — et c'est le point de ce lot.
         *
         * `if (app()->runningUnitTests()) return;` figurait ICI, en toute première
         * ligne. Motif écrit : « la CI ne doit pas tenter d'envoyer du WhatsApp ».
         * L'intention était juste, la portée beaucoup trop large : elle rendait ce
         * service ENTIÈREMENT intestable. Aucun test ne pouvait vérifier qu'une
         * erreur serveur atteint quelqu'un — et c'est ainsi qu'il a pu n'avoir qu'un
         * seul canal, muet sur cette installation, sans que rien ne le signale.
         *
         * Le garde ne protégeait d'ailleurs presque rien : phpunit.xml impose
         * MAIL_MAILER=array, et le driver WhatsApp vaut « log » par défaut. Le seul
         * risque réel est un appel HTTP sortant si un test choisissait un vrai
         * provider. Ce risque est donc traité LÀ OÙ IL SE TROUVE — sur le canal
         * WhatsApp, plus bas — au lieu d'aveugler la méthode entière.
         */

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

        /*
         * LES ADMINS, QU'ILS AIENT UN NUMÉRO OU NON.
         *
         * La requête exigeait `whereNotNull('whatsapp_phone')` : un administrateur
         * sans numéro renseigné était donc écarté de TOUT — pas seulement du
         * WhatsApp. C'était le seul canal, il n'y avait rien d'autre à rater ;
         * maintenant qu'il y en a trois, la condition doit porter sur l'ENVOI
         * WhatsApp et non sur la SÉLECTION des destinataires.
         *
         * Relation `userRole` (et non `role`, inexistante — un whereHas('role')
         * lèverait une BadMethodCallException qui sauterait au catch et
         * neutraliserait même le repli ci-dessous).
         */
        // Déclaration UNIQUE de l'audience « administrateurs » : ce bloc en
        // portait sa propre version, sans écarter les comptes DÉSACTIVÉS — une
        // alerte adressée à quelqu'un qui a quitté l'exploitation.
        $admins = \App\Support\Administrators::all();

        /*
         * TROIS CANAUX, TROIS TRY/CATCH SÉPARÉS.
         *
         * Cette alerte n'existait que sur WhatsApp. Le canal étant en mode
         * « journal » sur cette installation, AUCUNE erreur serveur n'était donc
         * signalée à personne — ni 500, ni tâche planifiée morte. C'est l'alerte de
         * dernier recours : celle dont dépend la découverte de toutes les autres
         * pannes, y compris celles du 14 juillet.
         *
         * POURQUOI PAS broadcast() : cette méthode est appelée PENDANT une
         * exception, souvent une exception de base de données ou de cache. Le hub
         * lit les préférences de chaque destinataire, résout des cartes de
         * destination et écrit en base — c'est-à-dire qu'il ajoute des chemins
         * d'échec au moment le plus fragile. Un rapporteur d'erreur qui échoue
         * détruit l'information qu'il devait transmettre : c'est exactement ce qui
         * s'est produit le 14/07 (cf. l'en-tête de cette classe).
         *
         * Chaque canal est donc tenté séparément, et l'échec de l'un n'empêche pas
         * les autres. Un canal muet vaut mieux que trois canaux perdus.
         */

        // ─── 1. WhatsApp — seulement pour qui a un numéro ───
        try {
            // Le seul canal susceptible de provoquer un appel HTTP sortant : on
            // refuse l'envoi en test si un vrai provider est configuré. En mode
            // « journal » — la valeur par défaut, et l'état de cette installation —
            // rien ne sort, l'envoi est donc joué normalement et vérifiable.
            $driverReel = app()->runningUnitTests()
                && (string) \App\Models\Setting::get('whatsapp.driver', 'log') !== 'log';

            $whatsapp = $driverReel ? null : app(WhatsAppService::class);

            foreach ($whatsapp ? $admins->filter(fn ($a) => filled($a->whatsapp_phone)) : [] as $admin) {
                $whatsapp->send($admin->whatsapp_phone, $message, [
                    'user_id' => $admin->id,
                    'type'    => 'system_error',
                    'title'   => 'Erreur Système',
                ]);
            }
        } catch (\Throwable $alertError) {
            Log::error("ErrorAlertService: alerte WhatsApp non émise — {$alertError->getMessage()}");
        }

        // ─── 2. Cloche in-app — le canal qui ne coûte rien et ne dépend d'aucun
        //        provider extérieur. C'est là que le promoteur regarde.
        try {
            foreach ($admins as $admin) {
                $admin->notify(new \App\Notifications\AlertNotification(
                    [
                        'type'     => 'system_error',
                        'title'    => 'Erreur Système',
                        'message'  => $message,
                        'severity' => 'critique',
                        'url'      => null,
                        // Le terrain n'a rien à faire d'une trace de pile : elle est
                        // pour qui administre. On l'amène à son centre d'alertes.
                        'mobile_url' => '/alertes',
                    ],
                    ['database']
                ));
            }
        } catch (\Throwable $alertError) {
            Log::error("ErrorAlertService: cloche non émise — {$alertError->getMessage()}");
        }

        // ─── 3. E-mail à l'adresse admin — le seul canal qui sorte du serveur sans
        //        dépendre d'un provider WhatsApp. Vide = inactif, comme ailleurs.
        try {
            $adminEmail = (string) \App\Models\Setting::get('whatsapp.admin_email', '');

            if ($adminEmail !== '') {
                \Illuminate\Support\Facades\Notification::route('mail', $adminEmail)
                    ->notify(new \App\Notifications\AlertNotification(
                        [
                            'type'     => 'system_error',
                            'title'    => 'Erreur Système',
                            'message'  => $message,
                            'severity' => 'critique',
                            'url'      => null,
                            'mobile_url' => '/alertes',
                        ],
                        ['mail']
                    ));
            }
        } catch (\Throwable $alertError) {
            Log::error("ErrorAlertService: e-mail non émis — {$alertError->getMessage()}");
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
