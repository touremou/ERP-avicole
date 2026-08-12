<?php

namespace App\Http\Controllers;

use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class NotificationTemplateController extends Controller
{
    /**
     * VALEURS D'EXEMPLE POUR UN ENVOI DE TEST.
     *
     * Les clefs sont EXACTEMENT les variables déclarées dans
     * NotificationTemplate::catalog() — aucune inventée : une variable absente du
     * catalogue est déjà refusée à la saisie (cf. unknownVariables), et une valeur
     * d'exemple pour une variable inexistante donnerait à l'essai une allure de
     * réussite trompeuse.
     *
     * Les valeurs sont VISIBLEMENT fictives. Un test qui ressemble à une vraie
     * alerte se confond avec elle : « Lot TEST-001 » se lit du premier coup d'œil,
     * « Lot CHA-2601 » se prend pour un incident.
     */
    private const SAMPLE_VARIABLES = [
        'emoji'      => '🧪',
        'header'     => 'ESSAI DE MODÈLE',
        'batch_code' => 'TEST-001',
        'building'   => 'Bâtiment d\'essai',
        'farm'       => 'Site d\'essai',
        'deaths'     => '12',
        'rate'       => '4,2',
        'daily_rate' => '0,8',
        'remaining'  => '288',
        'capacity'   => '500',
        'count'      => '3',
        'days'       => '5',
        'quantity'   => '120',
        'threshold'  => '200',
        'unit'       => 'kg',
        'item_name'  => 'Article d\'essai',
        'category'   => 'conso',
        'amount'     => '1 250 000',
        'total'      => '1 250 000',
        'client'     => 'Client d\'essai',
        'reference'  => 'ESSAI-0001',
        'method'     => 'espèces',
        'status'     => 'à vérifier',
        'severity'   => 'attention',
        'level'      => '30',
        'autonomy'   => '4',
        'source'     => 'Groupe électrogène d\'essai',
        'symptoms'   => 'aucun — message d\'essai',
        'employees'  => 'Employé d\'essai',
        'items'      => 'Article d\'essai (120 kg)',
        'flags'      => 'aucun',
    ];

    /**
     * ENVOI D'ESSAI D'UN MODÈLE — teste la configuration ET le texte.
     *
     * Les essais existants (WhatsApp, e-mail, SMS, push) envoient un texte CODÉ EN
     * DUR : ils prouvent que le canal fonctionne, et rien du tout sur les modèles.
     * On pouvait donc écrire un modèle troué, le voir accepté, et ne découvrir le
     * blanc à l'endroit du chiffre qu'au premier vrai incident.
     *
     * Cet essai rend le modèle TEL QU'IL EST ENREGISTRÉ, avec des valeurs
     * visiblement fictives, et le fait partir sur les canaux de l'utilisateur.
     * Le compte rendu dit canal par canal ce qui est parti et ce qui ne l'est pas
     * — un « envoyé » global sur quatre canaux dont un muet est ce qui a fait
     * perdre le plus de temps à cette exploitation.
     */
    public function sendTest(NotificationTemplate $template)
    {
        if (Gate::denies('admin.S')) {
            return redirect()->route('dashboard')->with('error', 'Accès réservé à l\'administrateur.');
        }

        $user = Auth::user();

        $body = NotificationTemplate::interpolate(
            NotificationTemplate::bodyFor($template->key),
            self::SAMPLE_VARIABLES
        );

        // Variables du modèle restées sans valeur d'exemple : le rendu montrerait un
        // blanc, et l'utilisateur croirait son modèle fautif. On le dit.
        $missing = array_values(array_diff(
            NotificationTemplate::catalog()[$template->key]['variables'] ?? [],
            array_keys(self::SAMPLE_VARIABLES)
        ));

        $report = [];

        // ─── Cloche : toujours disponible, sans configuration ───
        $user->notify(new \App\Notifications\AlertNotification(
            [
                'type'     => 'test',
                'title'    => 'Essai — ' . $template->label,
                'message'  => $body,
                'severity' => 'normal',
                'url'      => route('notifications.templates', absolute: false),
                'mobile_url' => '/alertes',
            ],
            ['database']
        ));
        $report[] = 'cloche : envoyé';

        // ─── E-mail ───
        if (! $user->email) {
            $report[] = 'e-mail : aucune adresse sur votre compte';
        } else {
            try {
                // notifyNow : sans la file, pour que l'échec SMTP remonte ICI plutôt
                // que dans un journal que personne ne lit.
                \Illuminate\Support\Facades\Notification::route('mail', $user->email)
                    ->notifyNow(new \App\Notifications\AlertNotification(
                        [
                            'type'     => 'test',
                            'title'    => 'Essai — ' . $template->label,
                            'message'  => $body,
                            'severity' => 'normal',
                        ],
                        ['mail']
                    ));

                $report[] = 'e-mail : envoyé à ' . $user->email;
            } catch (\Throwable $e) {
                $report[] = 'e-mail : ÉCHEC — ' . $e->getMessage();
            }
        }

        // ─── WhatsApp : seulement si un provider réel est actif ───
        $driver = (string) setting('whatsapp.driver', 'log');
        $phone = $user->whatsapp_phone ?: (string) setting('whatsapp.admin_phone', '');

        if ($driver === 'log') {
            $report[] = 'WhatsApp : ignoré (aucun provider actif, mode journal)';
        } elseif (! $phone) {
            $report[] = 'WhatsApp : aucun numéro sur votre compte ni en Paramètres';
        } else {
            $sent = app(\App\Services\WhatsAppService::class)->send($phone, $body, [
                'user_id' => $user->id,
                'type'    => 'test',
                'title'   => 'Essai de modèle',
            ]);

            $report[] = $sent
                ? "WhatsApp : envoyé au {$phone}"
                : 'WhatsApp : ÉCHEC — voir Notifications › Journal pour la réponse du provider';
        }

        $flash = 'Essai de « ' . $template->label . ' » — ' . implode(' · ', $report);

        if ($missing !== []) {
            $flash .= ' — variables sans valeur d\'exemple, affichées vides : ' . implode(', ', $missing);
        }

        return back()->with('success', $flash);
    }

    /**
     * Liste éditable des modèles de notification. On garantit l'existence d'une
     * ligne par entrée du catalogue (création paresseuse) pour que toute
     * notification livrée soit personnalisable, même ajoutée après la migration.
     */
    public function index()
    {
        if (Gate::denies('admin.S')) {
            return redirect()->route('dashboard')->with('error', 'Accès réservé à l\'administrateur.');
        }

        $templates = collect(NotificationTemplate::catalog())->map(function ($meta, $key) {
            $row = NotificationTemplate::firstOrCreate(
                ['key' => $key, 'channel' => 'whatsapp'],
                ['label' => $meta['label'], 'body' => $meta['default'], 'is_active' => true]
            );

            return [
                'model'     => $row,
                'variables' => $meta['variables'],
                'default'   => $meta['default'],
            ];
        });

        return view('notifications.templates', compact('templates'));
    }

    public function update(Request $request, NotificationTemplate $template)
    {
        if (Gate::denies('admin.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'body'      => 'required|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);

        // Une variable inconnue est remplacée par du VIDE à l'envoi : le message
        // partirait troué, sans que rien ne le signale — privé justement du
        // chiffre qui le rendait utile. On refuse tant que c'est corrigeable.
        $unknown = NotificationTemplate::unknownVariables($template->key, $validated['body']);

        if ($unknown !== []) {
            $available = implode(', ', array_map(
                fn ($v) => '{{' . $v . '}}',
                NotificationTemplate::catalog()[$template->key]['variables'] ?? []
            ));

            return back()->withInput()->with('error',
                'Variable(s) inconnue(s) : ' . implode(', ', array_map(fn ($v) => '{{' . $v . '}}', $unknown))
                . ". Elles seraient remplacées par du vide dans le message envoyé."
                . ($available !== '' ? " Disponibles pour ce modèle : {$available}." : '')
            );
        }

        $template->update([
            'body'      => $validated['body'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', "Modèle « {$template->label} » mis à jour.");
    }

    /**
     * Restaure le modèle à son texte d'origine (catalogue livré).
     */
    public function reset(NotificationTemplate $template)
    {
        if (Gate::denies('admin.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $default = NotificationTemplate::catalog()[$template->key]['default'] ?? $template->body;
        $template->update(['body' => $default, 'is_active' => true]);

        return back()->with('success', "Modèle « {$template->label} » réinitialisé.");
    }
}
