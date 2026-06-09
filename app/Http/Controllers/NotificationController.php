<?php

namespace App\Http\Controllers;

use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Services\NotificationHub;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

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
     * Envoie un message de test WhatsApp.
     */
    public function sendTest(WhatsAppService $whatsapp)
    {
        $phone = Auth::user()->whatsapp_phone;

        if (! $phone) {
            return back()->with('error', 'Aucun numéro WhatsApp configuré. Renseignez votre numéro d\'abord.');
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

        return back()->with(
            $result ? 'success' : 'error',
            $result ? 'Message de test envoyé ! Vérifiez votre WhatsApp.' : 'Échec de l\'envoi. Vérifiez le numéro et la configuration du provider.'
        );
    }

    /**
     * Historique des notifications (admin).
     */
    public function logs(Request $request)
    {
        if (Gate::denies('admin.S')) return back()->with('error', 'Accès réservé aux administrateurs.');

        $query = NotificationLog::with('user');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $logs = $query->latest()->paginate(30);

        $stats = [
            'today_sent'   => NotificationLog::today()->where('status', 'sent')->count(),
            'today_failed' => NotificationLog::today()->where('status', 'failed')->count(),
            'total'        => NotificationLog::count(),
        ];

        return view('notifications.logs', compact('logs', 'stats'));
    }
}
