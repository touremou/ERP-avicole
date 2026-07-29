<?php

namespace App\Http\Controllers;

use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NotificationTemplateController extends Controller
{
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
