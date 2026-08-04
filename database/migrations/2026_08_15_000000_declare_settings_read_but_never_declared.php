<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TREIZE RÉGLAGES LUS PARTOUT, DÉCLARÉS NULLE PART.
 *
 * Le code lit `setting('rh.contract_notice_days', 30)` à quatre endroits — dont
 * la tâche planifiée qui alerte sur les contrats qui expirent. Mais cette clef
 * n'existe pas dans la table des réglages : elle ne s'affiche donc dans aucun
 * écran de Paramètres, et l'application retourne éternellement son repli.
 *
 * Un préavis figé à 30 jours qu'aucun bouton ne peut changer, et rien à l'écran
 * pour le dire. C'est la même famille de défaut que le nom d'exploitation, que
 * `is_active` sur les fermes, que l'URL des notifications : des LECTEURS, pas
 * de RÉDACTEUR. Elle est revenue une demi-douzaine de fois ces dernières
 * semaines, à chaque fois découverte par hasard.
 *
 * On déclare donc les treize, CHACUNE À LA VALEUR DE SON REPLI ACTUEL. Le
 * comportement d'aujourd'hui est donc rigoureusement inchangé : ce qui change,
 * c'est qu'ils deviennent visibles et réglables. Mettre au passage des valeurs
 * « meilleures » mélangerait deux décisions et rendrait tout écart inexplicable.
 *
 * Un test (SettingsDeclarationGuardTest) vérifie désormais que TOUTE clef lue
 * en dur dans le code est déclarée ici. La famille ne peut plus revenir en
 * silence.
 */
return new class extends Migration
{
    /**
     * [groupe, clef, valeur = repli actuel, type, libellé, unité, ordre]
     *
     * Les valeurs sont relevées une par une sur les sites de lecture ; aucune
     * n'est inventée.
     */
    private const SETTINGS = [
        // ─── Abattoir : les bandes de rendement et les tolérances ───
        ['abattoir', 'yield_target_min', '70', 'number',
            'Rendement carcasse — norme basse', '%', 100],
        ['abattoir', 'yield_target_max', '75', 'number',
            'Rendement carcasse — norme haute', '%', 101],
        ['abattoir', 'yield_alert_min', '65', 'number',
            'Rendement carcasse — seuil d’alerte (en dessous : anomalie)', '%', 102],
        ['abattoir', 'condemnation_tolerance', '2', 'number',
            'Taux de saisie sanitaire toléré', '%', 103],
        ['abattoir', 'tolerance_cutting_loss', '10', 'number',
            'Perte de découpe tolérée (bilan de masse)', '%', 104],
        ['abattoir', 'yield_ponte_est', '60-65', 'string',
            'Rendement indicatif — poule de réforme (fourchette affichée à la commande)', '%', 105],
        ['abattoir', 'yield_repro_est', '55-65', 'string',
            'Rendement indicatif — reproducteur (fourchette affichée à la commande)', '%', 106],

        // ─── Élevage ───
        ['elevage', 'incident_diagnosis_sla_days', '2', 'number',
            'Délai avant de signaler un incident sanitaire sans diagnostic', 'jours', 100],

        // ─── RH ───
        ['rh', 'contract_notice_days', '30', 'number',
            'Préavis d’alerte avant l’échéance d’un contrat', 'jours', 10],

        // ─── Ventes ───
        ['ventes', 'reminder_cooldown_days', '7', 'number',
            'Délai minimum entre deux relances de paiement au même client', 'jours', 20],

        // ─── Télémétrie (capteurs) ───
        ['telemetry', 'min_interval_seconds', '300', 'number',
            'Intervalle minimum entre deux relevés retenus d’un même capteur', 's', 1],
        ['telemetry', 'min_delta_c', '0.3', 'number',
            'Écart minimum pour retenir un relevé (sous ce seuil : bruit de mesure)', '°C', 2],
        ['telemetry', 'calibration_gap_c', '2', 'number',
            'Écart capteur / relevé manuel au-delà duquel un étalonnage est signalé', '°C', 3],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::SETTINGS as [$group, $key, $value, $type, $label, $unit, $order]) {
            // Idempotent : une valeur déjà réglée par la ferme n'est jamais
            // réécrite — ce serait annuler un choix d'exploitation.
            $exists = DB::table('settings')
                ->where('group', $group)->where('key', $key)->whereNull('farm_id')->exists();

            if ($exists) {
                continue;
            }

            DB::table('settings')->insert([
                'group'         => $group,
                'key'           => $key,
                'value'         => $value,
                'type'          => $type,
                'label'         => $label,
                'unit'          => $unit,
                'display_order' => $order,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::SETTINGS as [$group, $key]) {
            DB::table('settings')
                ->where('group', $group)->where('key', $key)->whereNull('farm_id')->delete();
        }
    }
};
