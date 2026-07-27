<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DEUX RÉGLAGES POUR UN SEUL SEUIL.
 *
 * Paramètres › Élevage proposait côte à côte :
 *
 *   • « Seuil alerte mortalité »        → elevage.mortality_alert
 *   • « Seuil alerte mortalité cumulée » → elevage.cumulative_mortality_alert_pct
 *
 * Deux champs, un seul seuil réel. `Batch::cumulativeMortalityThreshold()` lit le
 * second et retombe sur le premier, mais cinq écrans lisaient le PREMIER en
 * direct : le rapport technique, l'analyse financière santé, la vue consolidée,
 * l'écran de pointage et la fiche hebdomadaire par technicien. Éditer le champ
 * « cumulée » changeait donc l'alerte de l'observer, le tableau de bord et le
 * filtre surmortalité — et laissait les cinq autres écrans sur l'ancienne valeur.
 *
 * On ne garde qu'un champ, le mieux libellé. Et on PRÉSERVE le choix de la ferme :
 * si elle a réglé l'ancien champ sans toucher au nouveau, sa valeur est reportée
 * avant que le doublon ne disparaisse.
 */
return new class extends Migration
{
    private const LEGACY = 'mortality_alert';
    private const CANONICAL = 'cumulative_mortality_alert_pct';
    private const SHIPPED_DEFAULT = '5';

    public function up(): void
    {
        $rows = DB::table('settings')->where('group', 'elevage')
            ->whereIn('key', [self::LEGACY, self::CANONICAL])
            ->get()->keyBy('key');

        $legacy = $rows[self::LEGACY] ?? null;
        $canonical = $rows[self::CANONICAL] ?? null;

        // La ferme a réglé l'ancien champ, le nouveau est resté par défaut :
        // c'est l'ancien qui porte son intention.
        if ($legacy && $canonical
            && $legacy->value !== self::SHIPPED_DEFAULT
            && $canonical->value === self::SHIPPED_DEFAULT) {
            DB::table('settings')->where('id', $canonical->id)
                ->update(['value' => $legacy->value, 'updated_at' => now()]);
        }

        // Le nouveau champ doit exister avant qu'on retire l'ancien, sinon
        // l'accesseur retomberait sur son repli codé (5 %).
        if (! $canonical && $legacy) {
            DB::table('settings')->where('id', $legacy->id)->update([
                'key'        => self::CANONICAL,
                'label'      => 'Seuil alerte mortalité cumulée',
                'updated_at' => now(),
            ]);

            return;
        }

        if ($legacy && $canonical) {
            DB::table('settings')->where('id', $legacy->id)->delete();
        }

        // Les réglages sont servis depuis un cache : sans cette purge, le champ
        // supprimé resterait affiché jusqu'à expiration.
        \App\Models\Setting::clearCache();
    }

    public function down(): void
    {
        $canonical = DB::table('settings')->where('group', 'elevage')
            ->where('key', self::CANONICAL)->first();

        if (! $canonical) {
            return;
        }

        DB::table('settings')->updateOrInsert(
            ['group' => 'elevage', 'key' => self::LEGACY],
            [
                'value'         => $canonical->value,
                'type'          => 'number',
                'label'         => 'Seuil alerte mortalité',
                'unit'          => '%',
                'display_order' => $canonical->display_order,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]
        );

        \App\Models\Setting::clearCache();
    }
};
