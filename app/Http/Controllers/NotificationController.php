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
        $prefs = NotificationPreference::firstOrCreate(
            ['user_id' => Auth::id()],
            [
                'is_active'         => true,
                'channel_whatsapp'  => true,
                'channel_database'  => true,
                'channel_email'     => false,
                'daily_summary'     => true,
                'alert_mortality'   => true,
                'alert_stock'       => true,
                'alert_energy'      => true,
                'alert_sales'       => false,
                'alert_fraud'       => true,
            ]
        );

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
            'daily_summary'     => 'boolean',
            'alert_mortality'   => 'boolean',
            'alert_stock'       => 'boolean',
            'alert_energy'      => 'boolean',
            'alert_sales'       => 'boolean',
            'alert_fraud'       => 'boolean',
            'quiet_start'       => 'nullable|date_format:H:i',
            'quiet_end'         => 'nullable|date_format:H:i',
        ]);

        // Mettre à jour le numéro WhatsApp sur le user
        if (isset($validated['whatsapp_phone'])) {
            Auth::user()->update(['whatsapp_phone' => $validated['whatsapp_phone']]);
        }

        NotificationPreference::updateOrCreate(
            ['user_id' => Auth::id()],
            collect($validated)->except('whatsapp_phone')->toArray()
        );

        return back()->with('success', 'Préférences de notification mises à jour.');
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

            $url = $notification->data['url'] ?? null;
            if ($url) {
                return redirect($url);
            }
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
            if ($detail) {
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
            return back()->with('error', "Échec e-mail : " . Str::limit($e->getMessage(), 160) . ' — vérifiez MAIL_* / le serveur SMTP.');
        }

        $hint = config('mail.default') === 'log' ? " (mailer « log » : voir storage/logs/laravel.log)" : '';

        return back()->with('success', "E-mail de test envoyé à {$email}{$hint}.");
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
