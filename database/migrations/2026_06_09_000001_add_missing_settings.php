<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute les settings manquants référencés dans le code mais absents de la table.
 *
 * - abattoir.kpi_days    : utilisé dans SlaughterController::getKPI()
 * - general.weight_unit  : utilisé dans SlaughterController (export/stock mouvement)
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            [
                'group'         => 'abattoir',
                'key'           => 'kpi_days',
                'value'         => '30',
                'type'          => 'number',
                'label'         => 'Fenêtre KPI abattoir (jours)',
                'description'   => 'Nombre de jours glissants pour le calcul des KPIs abattoir (tableau de bord).',
                'unit'          => 'jours',
                'display_order' => 99,
                'is_sensitive'  => false,
            ],
            [
                'group'         => 'general',
                'key'           => 'weight_unit',
                'value'         => 'KG',
                'type'          => 'select',
                'label'         => 'Unité de poids',
                'description'   => 'Unité de mesure de poids utilisée dans les stocks et mouvements.',
                'options'       => 'KG,G,T',
                'unit'          => null,
                'display_order' => 12,
                'is_sensitive'  => false,
            ],
        ];

        foreach ($settings as $s) {
            DB::table('settings')->updateOrInsert(
                ['group' => $s['group'], 'key' => $s['key'], 'farm_id' => null],
                array_merge($s, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['kpi_days', 'weight_unit'])
            ->whereNull('farm_id')
            ->delete();
    }
};
