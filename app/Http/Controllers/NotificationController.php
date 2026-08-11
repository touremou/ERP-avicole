<?php

namespace App\Http\Controllers;

use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Services\NotificationHub;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    /**
     * Page de gestion des préférences de notification.
     */
    public function preferences()
    {
        // Valeurs livrées : NotificationPreference::DEFAULTS (source unique).
        $prefs = NotificationPreference::forUser(Auth::id());

        $recentLogs = NotificationLog::where('user_id', Auth::id())
            ->latest()
            ->take(20)
            ->get();

        $stats = [
            'total_sent'   => NotificationLog::where('user_id', Auth::id())->where('status', 'sent')->count(),
            'total_failed' => NotificationLog::where('user_id', Auth::id())->where('status', 'failed')->count(),
            'today_count'  => NotificationLog::where('user_id', Auth::id())->today()->count(),
        ];

        return view('notifications.preferences', compact('prefs', 'recentLogs', 'stats'));
    }

    /**
     * Met à jour les préférences.
     */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            'whatsapp_phone'    => 'nullable|string|max:30',
            'is_active'         => 'boolean',
            'channel_whatsapp'  => 'boolean',
            'channel_database'  => 'boolean',
            'channel_email'     => 'boolean',
            'channel_sms'       => 'boolean',
            // `channel_push` était LU par la diffusion mais absent d'ici ET de
            // l'écran : le canal ne pouvait ni s'afficher ni s'enregistrer. Ajouter
            // la case sans la valider n'aurait rien changé — la valeur serait tombée
            // à la porte.
            'channel_push'      => 'boolean',
            'daily_summary'     => 'boolean',
            'alert_mortality'   => 'boolean',
            'alert_stock'       => 'boolean',
            'alert_energy'      => 'boolean',
            'alert_sales'       => 'boolean',
            'alert_fraud'       => 'boolean',
            // MySQL stocke une colonne TIME et la relit « 22:00:00 ». Le champ du
            // formulaire la réaffiche telle quelle, le navigateur la resoumet avec
            // les secondes, et « H:i » seul la refusait : on ne pouvait plus rien
            // enregistrer sur cet écran — pas même le numéro WhatsApp, puisque
            // tout le formulaire tombait.
            //
            // Invisible en local : sans ligne de préférences, la valeur est nulle
            // et la validation ne voit jamais de secondes.
            'quiet_start'       => 'nullable|date_format:H:i,H:i:s',
            'quiet_end'         => 'nullable|date_format:H:i,H:i:s',
        ]);

        // Mettre à jour le numéro WhatsApp sur le user
        if (isset($validated['whatsapp_phone'])) {
            Auth::user()->update(['whatsapp_phone' => $validated['whatsapp_phone']]);
        }

        // Les colonnes d'heures sont NOT NULL : vider le champ envoyait null et
        // produisait une erreur 500. Un champ laissé vide signifie « ne change
        // rien » — le modèle n'a pas d'état « pas d'heures silencieuses »
        // (isQuietHour retombe toujours sur une fenêtre), l'inventer ici
        // reviendrait à décider d'une règle depuis un contrôleur.
        $payload = collect($validated)
            ->except('whatsapp_phone')
            ->reject(fn ($value, $key) => in_array($key, ['quiet_start', 'quiet_end'], true) && blank($value))
            ->toArray();

        NotificationPreference::updateOrCreate(['user_id' => Auth::id()], $payload);

        return back()->with('success', 'Préférences de notification mises à jour.');
    }

    /**
     * CENTRE D'ALERTES de l'utilisateur — l'historique qui manquait au web.
     *
     * La cloche n'affichait que les notifications NON LUES, et cliquer sur l'une
     * d'elles la marquait lue : elle disparaissait aussitôt, sans qu'on ait eu le
     * temps de la lire. Aucun écran ne permettait de la retrouver — « Historique »
     * du menu Notifications désigne le journal des messages SORTANTS
     * (WhatsApp/SMS), réservé aux administrateurs, pas ses propres alertes.
     *
     * Le mobile, lui, faisait déjà la bonne chose : son centre d'alertes liste
     * tout, les lues estompées. Le web était l'exception.
     *
     * Aucun droit de module requis : ce sont SES alertes.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $filter = $request->input('vue', 'toutes');

        $query = $user->notifications();

        if ($filter === 'non_lues') {
            $query->whereNull('read_at');
        }

        $notifications = $query->latest()->paginate((int) setting('general.items_per_page', 20))
            ->withQueryString();

        return view('notifications.index', [
            'notifications' => $notifications,
            'filter'        => $filter,
            'unreadCount'   => $user->unreadNotifications()->count(),
            'totalCount'    => $user->notifications()->count(),
        ]);
    }

    /**
     * Marque toutes les notifications in-app de l'utilisateur comme lues
     * (bouton « tout marquer lu » de la cloche).
     */
    public function markAllRead()
    {
        Auth::user()->unreadNotifications->markAsRead();

        return back()->with('success', 'Notifications marquées comme lues.');
    }

    /**
     * Marque UNE notification comme lue puis redirige vers sa cible (data['url'])
     * si elle existe — clic sur un élément de la cloche.
     */
    public function markRead(string $id)
    {
        $notification = Auth::user()->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsRead();

            // Les alertes ANTÉRIEURES à ce correctif n'ont pas d'adresse en base :
            // sans repli, elles resteraient mortes au clic — et ce sont justement
            // celles qui remplissent la cloche aujourd'hui. On retombe donc sur
            // l'écran du sujet, déduit du type.
            $url = $notification->data['url']
                ?? \App\Services\NotificationHub::destinationFor($notification->data['type'] ?? '');

            return redirect($url);
        }

        return back();
    }

    /**
     * Envoie un message de test WhatsApp.
     *
     * Priorité du destinataire : numéro personnel de l'utilisateur connecté,
     * à défaut le « Téléphone admin » global (whatsapp.admin_phone). Ceci évite
     * la friction « j'ai configuré l'API mais le test refuse » : si l'admin a
     * renseigné un numéro dans Paramètres, le test l'utilise directement.
     */
    public function sendTest(WhatsAppService $whatsapp)
    {
        $personalPhone = Auth::user()->whatsapp_phone;
        $adminPhone    = (string) setting('whatsapp.admin_phone', '');
        $phone         = $personalPhone ?: $adminPhone;
        $usingFallback = ! $personalPhone && $phone !== '';

        if (! $phone) {
            return back()->with('error', 'Aucun numéro WhatsApp disponible. Renseignez votre numéro ci-dessus (champ "Numéro WhatsApp") puis cliquez sur Enregistrer, ou définissez le « Téléphone admin » dans Paramètres > WhatsApp, puis réessayez.');
        }

        $driver = (string) setting('whatsapp.driver', 'log');
        if ($driver === 'log') {
            return back()->with('error', 'Le canal WhatsApp est en mode "log" (aucun provider actif). Choisissez un driver (CallMeBot, UltraMsg, WATI, Twilio) dans Paramètres > WhatsApp et renseignez la clé API pour envoyer de vrais messages.');
        }

        $message = "🧪 *Test AviSmart*\n\n"
            . "Ce message confirme que votre compte WhatsApp est bien connecté au système de notifications.\n\n"
            . "Utilisateur : " . Auth::user()->name . "\n"
            . "Date : " . now()->translatedFormat('d F Y à H:i') . "\n\n"
            . "— AviSmart ERP 🇬🇳";

        $result = $whatsapp->send($phone, $message, [
            'user_id' => Auth::id(),
            'type'    => 'test',
            'title'   => 'Test WhatsApp',
        ]);

        if (! $result) {
            $log = NotificationLog::where('recipient_phone', $phone)->where('type', 'test')->latest()->first();
            $detail = is_array($log?->provider_response)
                ? ($log->provider_response['error'] ?? $log->provider_response['body'] ?? null)
                : null;

            $error = 'Échec de l\'envoi vers ' . $phone . '. Vérifiez le numéro et la configuration du provider (clé API, instance).';

            // Aide ciblée sur un 403 / page « Forbidden » (cause fréquente) :
            // clé API absente/incorrecte, numéro non autorisé (CallMeBot exige
            // que le destinataire active d'abord le bot), ou blocage WAF/proxy.
            $status = is_array($log?->provider_response) ? ($log->provider_response['status'] ?? null) : null;
            $looksForbidden = $status == 403 || \Illuminate\Support\Str::contains(strtolower((string) $detail), 'forbidden');
            if ($looksForbidden) {
                $error .= $driver === 'callmebot'
                    ? ' (403) CallMeBot : le destinataire doit d\'abord AUTORISER le bot (lui envoyer le message d\'activation) pour obtenir une clé API valide, et la clé doit correspondre à CE numéro. Un 403 peut aussi venir d\'un blocage WAF/proxy de l\'hébergeur.'
                    : ' (403) Le fournisseur a refusé la requête : clé API/instance invalide, ou blocage WAF/proxy de l\'hébergeur.';
            } elseif ($detail) {
                $error .= ' Détail : ' . \Illuminate\Support\Str::limit((string) $detail, 150);
            }
            if (Gate::allows('notifications.S')) {
                $error .= ' Voir Notifications > Historique pour le détail complet.';
            }

            return back()->with('error', $error);
        }

        $sentTo = $usingFallback
            ? "Message de test envoyé au numéro admin ({$phone}) ! Vérifiez ce WhatsApp. Astuce : renseignez votre numéro personnel ci-dessus pour recevoir vos propres alertes."
            : 'Message de test envoyé ! Vérifiez votre WhatsApp.';

        return back()->with('success', $sentTo);
    }

    /** Test du canal SMS (passerelle locale). */
    public function sendTestSms(SmsService $sms)
    {
        $phone = Auth::user()->whatsapp_phone ?: (string) setting('whatsapp.admin_phone', '');
        if (! $phone) {
            return back()->with('error', 'Aucun numéro disponible. Renseignez votre numéro (WhatsApp/mobile) ou le « Téléphone admin ».');
        }

        $driver = (string) setting('sms.driver', config('services.sms.driver', 'log'));
        $ok = $sms->send($phone, "Test SMS AviSmart — " . now()->format('d/m H:i'), [
            'user_id' => Auth::id(), 'type' => 'test', 'title' => 'Test SMS',
        ]);

        if ($driver === 'log') {
            return back()->with('success', "SMS en mode « log » (aucune passerelle active) : message journalisé. Configurez sms.driver=http et l'URL de passerelle (Réglages › SMS) pour de vrais SMS.");
        }

        return $ok
            ? back()->with('success', "SMS de test envoyé à {$phone}.")
            : back()->with('error', "Échec de l'envoi SMS. Vérifiez la passerelle (URL, clé) — détail dans Notifications › Historique.");
    }

    /** Test du canal e-mail (envoi SYNCHRONE pour faire remonter les erreurs SMTP). */
    public function sendTestMail()
    {
        $email = Auth::user()->email ?: (string) setting('whatsapp.admin_email', '');
        if (! $email) {
            return back()->with('error', 'Aucune adresse e-mail disponible pour le test.');
        }

        try {
            // notifyNow : contourne la file → les erreurs SMTP remontent ici.
            Notification::route('mail', $email)->notifyNow(new \App\Notifications\AlertNotification(
                [
                    'type'     => 'test',
                    'title'    => 'Test e-mail AviSmart',
                    'message'  => 'Ce message confirme que la configuration e-mail (SMTP) fonctionne.',
                    'severity' => 'normal',
                ],
                ['mail']
            ));
        } catch (\Throwable $e) {
            // Diagnostic : on renvoie la config SMTP EFFECTIVE (sans mot de passe)
            // pour repérer un décalage host/port/scheme/username/expéditeur — cause
            // n°1 des « Failed to authenticate » (identifiants ou chiffrement).
            $smtp = config('mail.mailers.smtp');
            $ctx = sprintf(
                'host=%s:%s scheme=%s user=%s from=%s',
                $smtp['host'] ?? '?', $smtp['port'] ?? '?',
                $smtp['scheme'] ?? 'auto', $smtp['username'] ?? '(vide)',
                config('mail.from.address') ?? '?'
            );

            // On NOMME l'incohérence quand on la voit, au lieu de laisser
            // relire une liste de choses à vérifier. L'expéditeur différent du
            // compte authentifié est refusé par la plupart des hébergements
            // mutualisés, et c'est invisible dans le message brut du serveur.
            $from = (string) (config('mail.from.address') ?? '');
            $user = (string) ($smtp['username'] ?? '');

            $mismatch = ($from !== '' && $user !== '' && strcasecmp($from, $user) !== 0)
                ? " ⚠️ L'expéditeur ({$from}) diffère du compte authentifié ({$user}) :"
                    . ' la plupart des hébergements le refusent. Mettez MAIL_FROM_ADDRESS='
                    . $user . '.'
                : '';

            $hint = $mismatch;
            if (Str::contains($e->getMessage(), ['authenticate', 'Authenticator', '535', '534'])) {
                // Hôte mutualisé (PlanetHoster) : port 465 + SSL, et l'expéditeur
                // (MAIL_FROM_ADDRESS) doit correspondre à la boîte authentifiée.
                $hint .= ' — Auth SMTP refusée : vérifiez MAIL_PASSWORD (guillemets si caractères spéciaux),'
                    . ' que MAIL_FROM_ADDRESS = MAIL_USERNAME, et le couple port/chiffrement'
                    . ' (465→MAIL_SCHEME=smtps, ou 587→MAIL_SCHEME=null + TLS).';
            }

            return back()->with('error', 'Échec e-mail : ' . Str::limit($e->getMessage(), 140) . " [{$ctx}]{$hint}");
        }

        // « ENVOYÉ » NE DOIT PAS MENTIR.
        //
        // Le canal « log » écrit dans un fichier et rend la main sans erreur : le
        // message annonçait donc un envoi réussi pour un message que personne ne
        // recevra jamais, la nuance étant reléguée entre parenthèses. On cherche
        // alors le problème du côté du destinataire, de ses spams, de son
        // fournisseur — partout sauf là où il est.
        if (config('mail.default') === 'log') {
            return back()->with('error',
                "RIEN N'A ÉTÉ ENVOYÉ : le canal e-mail est en mode « journal ». Le message a été"
                . " écrit dans storage/logs/laravel.log, pas expédié à {$email}."
                . ' Choisissez « smtp » dans Réglages → E-mail pour envoyer réellement.'
            );
        }

        // Sur un SUCCÈS aussi, on dit PAR OÙ c'est parti. Un e-mail accepté par le
        // serveur puis classé en indésirable est indiscernable d'un e-mail jamais
        // parti, si l'on ne sait pas quel serveur l'a accepté.
        $smtp = config('mail.mailers.smtp');
        $via = sprintf(
            '%s:%s, expéditeur %s',
            $smtp['host'] ?? '?', $smtp['port'] ?? '?', config('mail.from.address') ?? '?'
        );

        return back()->with('success',
            "E-mail de test accepté par le serveur pour {$email} — via {$via}."
            . ' Si rien n\'arrive, vérifiez les indésirables : le serveur l\'a bien pris en charge.'
        );
    }

    /**
     * Historique des notifications (admin).
     */
    public function logs(Request $request)
    {
        if (Gate::denies('notifications.S')) return back()->with('error', 'Accès réservé aux administrateurs.');

        $query = NotificationLog::with('user');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $logs = $query->latest()->paginate((int) setting('general.items_per_page', 20));

        $stats = [
            'today_sent'   => NotificationLog::today()->where('status', 'sent')->count(),
            'today_failed' => NotificationLog::today()->where('status', 'failed')->count(),
            'total'        => NotificationLog::count(),
        ];

        return view('notifications.logs', compact('logs', 'stats'));
    }
}
