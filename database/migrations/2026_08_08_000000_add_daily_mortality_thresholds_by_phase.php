<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEUIL DE MORTALITÉ QUOTIDIENNE PAR PHASE.
 *
 * Une mortalité de 0,8 %/jour est normale sur des poussins de chair à J3 et
 * alarmante sur des poulets en finition. L'application n'avait pourtant qu'un
 * seuil PLAT (`elevage.daily_mortality_alert_pct` = 0,5 %) : il criait au loup
 * la première semaine et laissait passer une dérive de finition.
 *
 * La courbe par âge existait — écrite avec soin, commentée — dans
 * `DashboardService::getDailyMortalityThreshold()`. Ce service n'était appelé
 * par AUCUN code de l'application : seul son propre test le touchait. La règle
 * la mieux pensée du module était donc la seule à ne jamais s'appliquer.
 *
 * On la reprend ici, VALEUR PAR VALEUR, telle qu'elle était écrite, et on
 * l'expose au paramétrage : la ferme peut l'ajuster sur ses propres relevés, ou
 * revenir au comportement d'avant en mettant 0,5 partout.
 *
 * Les phases suivent le SECTEUR d'aliment (cf. Batch::feedSector()) : la même
 * notion de phase que celle qui pilote déjà l'alimentation, plutôt qu'un second
 * découpage à maintenir en parallèle.
 */
return new class extends Migration
{
    /** [clef, libellé, valeur reprise de la courbe, ordre d'affichage] */
    private const THRESHOLDS = [
        ['mortality_pct_chair_demarrage',   'Seuil mortalité/jour — Chair démarrage (≤ J7)',    '1.0', 21],
        ['mortality_pct_chair_croissance',  'Seuil mortalité/jour — Chair croissance (≤ J28)',  '0.5', 22],
        ['mortality_pct_chair_finition',    'Seuil mortalité/jour — Chair finition',            '0.2', 23],
        ['mortality_pct_ponte_poulette',    'Seuil mortalité/jour — Ponte poulette (≤ J42)',    '0.5', 24],
        ['mortality_pct_ponte_production',  'Seuil mortalité/jour — Ponte en production',       '0.1', 25],
        ['mortality_pct_autres',            'Seuil mortalité/jour — Autres élevages',           '0.2', 26],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::THRESHOLDS as [$key, $label, $value, $order]) {
            $exists = DB::table('settings')
                ->where('group', 'elevage')->where('key', $key)->exists();

            if ($exists) {
                continue;   // idempotent : on n'écrase pas un seuil déjà ajusté
            }

            DB::table('settings')->insert([
                'group'         => 'elevage',
                'key'           => $key,
                'value'         => $value,
                'type'          => 'number',
                'label'         => $label,
                'unit'          => '%',
                'display_order' => $order,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'elevage')
            ->whereIn('key', array_column(self::THRESHOLDS, 0))->delete();
    }
};
