<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * LES POIDS VIFS « MOYENS » N'ESTIMENT PLUS RIEN.
 *
 * `elevage.avg_weight_chair` (2,2 kg) et `elevage.avg_weight_ponte` (1,8 kg)
 * ne servaient qu'à la clôture d'un lot, pour ESTIMER un indice de
 * consommation : effectif restant × poids réglé — 1,8 kg pour un tilapia
 * comme pour un bélier, pesées réelles ignorées. La clôture lit désormais
 * l'indice de la fiche (Batch::fcr_corrected, sur les pesées). Un réglage
 * offert à l'écran que rien ne lit ment à qui le modifie : on les retire.
 */
return new class extends Migration
{
    private const CLEFS = ['avg_weight_chair', 'avg_weight_ponte'];

    public function up(): void
    {
        DB::table('settings')->where('group', 'elevage')->whereIn('key', self::CLEFS)->delete();
    }

    public function down(): void
    {
        foreach ([
            ['key' => 'avg_weight_chair', 'value' => '2.2', 'label' => 'Poids vif moyen chair',    'display_order' => 7],
            ['key' => 'avg_weight_ponte', 'value' => '1.8', 'label' => 'Poids vif moyen pondeuse', 'display_order' => 8],
        ] as $ligne) {
            DB::table('settings')->updateOrInsert(
                ['group' => 'elevage', 'key' => $ligne['key'], 'farm_id' => null],
                $ligne + ['type' => 'number', 'unit' => 'kg', 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
};
