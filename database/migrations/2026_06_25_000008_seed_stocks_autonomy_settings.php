<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Câble et rend éditables les 2 seuils d'autonomie de stock du tableau de bord.
 *
 * Le code les lisait sous le groupe « stock » (singulier) alors que le module —
 * et l'onglet Paramètres — est « stocks » (pluriel) : ils n'étaient donc ni
 * trouvés ni éditables (toujours le défaut). Le contrôleur lit désormais
 * « stocks.* » ; on sème ici les lignes correspondantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['key' => 'autonomy_period_days',    'value' => '30', 'label' => "Période d'analyse de l'autonomie stock"],
            ['key' => 'critical_days_threshold', 'value' => '3',  'label' => "Seuil critique d'autonomie (jours)"],
        ];

        $order = 60;
        foreach ($rows as $r) {
            DB::table('settings')->updateOrInsert(
                ['group' => 'stocks', 'key' => $r['key']],
                [
                    'value' => $r['value'], 'type' => 'number', 'label' => $r['label'],
                    'unit' => 'jours', 'display_order' => $order++,
                    'updated_at' => $now, 'created_at' => $now,
                ]
            );
        }

        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'stocks')
            ->whereIn('key', ['autonomy_period_days', 'critical_days_threshold'])->delete();
        Setting::clearCache();
    }
};
