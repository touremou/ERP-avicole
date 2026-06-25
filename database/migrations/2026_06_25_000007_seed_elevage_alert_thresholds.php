<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rend ÉDITABLES les seuils d'alerte élevage qui étaient lus par le code via un
 * défaut codé en dur mais absents de la table `settings` (donc invisibles dans
 * Paramètres › Élevage). On les sème avec leur valeur par défaut actuelle :
 * comportement inchangé, mais désormais paramétrables par un responsable.
 *
 * Idempotent (updateOrInsert sur group+key) : ne crée pas de doublon et ne
 * réécrit pas une valeur déjà ajustée par l'utilisateur.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['key' => 'daily_mortality_alert_pct',      'value' => '0.5', 'unit' => '%',      'label' => 'Seuil alerte mortalité quotidienne'],
            ['key' => 'daily_mortality_alert_min',      'value' => '3',   'unit' => 'têtes', 'label' => 'Mortalité quotidienne minimale pour alerter'],
            ['key' => 'cumulative_mortality_alert_pct', 'value' => '5',   'unit' => '%',      'label' => 'Seuil alerte mortalité cumulée'],
            ['key' => 'lameness_alert_pct',             'value' => '5',   'unit' => '%',      'label' => 'Seuil alerte boiterie (bien-être)'],
            ['key' => 'pecking_alert_pct',              'value' => '2',   'unit' => '%',      'label' => 'Seuil alerte picage (bien-être)'],
            ['key' => 'welfare_window_days',            'value' => '7',   'unit' => 'jours', 'label' => 'Fenêtre de suivi bien-être'],
            ['key' => 'protocol_overdue_window_days',   'value' => '30',  'unit' => 'jours', 'label' => 'Fenêtre « soins en retard »'],
            ['key' => 'sanitary_break_days',            'value' => '14',  'unit' => 'jours', 'label' => 'Durée du vide sanitaire'],
        ];

        $order = 80;
        foreach ($rows as $r) {
            DB::table('settings')->updateOrInsert(
                ['group' => 'elevage', 'key' => $r['key']],
                [
                    'value'         => $r['value'],
                    'type'          => 'number',
                    'label'         => $r['label'],
                    'unit'          => $r['unit'],
                    'display_order' => $order++,
                    'updated_at'    => $now,
                    'created_at'    => $now,
                ]
            );
        }

        // Le cache des paramètres ne s'invalide pas tout seul sur un INSERT direct.
        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'elevage')->whereIn('key', [
            'daily_mortality_alert_pct', 'daily_mortality_alert_min', 'cumulative_mortality_alert_pct',
            'lameness_alert_pct', 'pecking_alert_pct', 'welfare_window_days',
            'protocol_overdue_window_days', 'sanitary_break_days',
        ])->delete();
    }
};
