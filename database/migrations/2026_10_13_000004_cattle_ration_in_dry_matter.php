<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BOVINS : RATION EN MATIÈRE SÈCHE, COMME LES PETITS RUMINANTS.
 *
 * Décision de l'exploitation. Les normes bovines exprimaient la ration en
 * fourrage FRAIS (12 kg/j pour un veau de 80 kg) quand celles des ovins et
 * caprins étaient en matière sèche : deux échelles sous la même étiquette.
 * Seules les lignes encore à la valeur livrée sont reprises — une valeur
 * ajustée par la ferme est respectée.
 */
return new class extends Migration
{
    /** [souche, semaine, ration livrée, eau livrée, ration MS, eau] */
    private const LIGNES = [
        ["Bovin N'Dama",           1,  12000, 25000, 2400,  10000],
        ["Bovin N'Dama",           26, 22000, 40000, 4900,  22000],
        ["Bovin N'Dama",           52, 28000, 50000, 7300,  32000],
        ['Vache Laitière (Métis)', 1,  13000, 30000, 2700,  11000],
        ['Vache Laitière (Métis)', 52, 35000, 70000, 12000, 60000],
        ['Zébu Gobra',             1,  14000, 28000, 2700,  11000],
        ['Zébu Gobra',             26, 26000, 45000, 5900,  26000],
        ['Zébu Gobra',             52, 32000, 60000, 8800,  40000],
    ];

    public function up(): void
    {
        foreach (self::LIGNES as [$souche, $semaine, $ancienneRation, $ancienneEau, $ration, $eau]) {
            DB::table('production_norms')
                ->where('model_name', $souche)->where('week_number', $semaine)
                ->where('target_feed_daily', $ancienneRation)
                ->update(['target_feed_daily' => $ration]);

            DB::table('production_norms')
                ->where('model_name', $souche)->where('week_number', $semaine)
                ->where('target_water_daily', $ancienneEau)
                ->update(['target_water_daily' => $eau]);
        }
    }

    public function down(): void
    {
        foreach (self::LIGNES as [$souche, $semaine, $ancienneRation, $ancienneEau, $ration, $eau]) {
            DB::table('production_norms')
                ->where('model_name', $souche)->where('week_number', $semaine)
                ->where('target_feed_daily', $ration)
                ->update(['target_feed_daily' => $ancienneRation]);

            DB::table('production_norms')
                ->where('model_name', $souche)->where('week_number', $semaine)
                ->where('target_water_daily', $eau)
                ->update(['target_water_daily' => $ancienneEau]);
        }
    }
};
