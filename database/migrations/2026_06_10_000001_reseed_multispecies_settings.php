<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La migration 2026_06_09_100005_add_multispecies_settings n'a pas inséré ses
 * lignes (table settings vide pour ces clés malgré le statut "Ran"). On la
 * rejoue ici de façon idempotente (updateOrInsert) pour restaurer les
 * paramètres multi-espèces, dont elevage.cycle_ovin_engraissement déjà
 * consommé par App\Models\Batch.
 */
return new class extends Migration {
    public function up(): void {
        $settings = [
            // Ruminants — Ovins
            ['group'=>'elevage','key'=>'cycle_ovin_engraissement','value'=>'90','type'=>'number','label'=>'Durée engraissement ovin','unit'=>'jours','display_order'=>20],
            ['group'=>'elevage','key'=>'cycle_ovin_reproducteur','value'=>'180','type'=>'number','label'=>'Durée cycle reproducteur ovin','unit'=>'jours','display_order'=>21],
            ['group'=>'elevage','key'=>'gmq_cible_ovin','value'=>'120','type'=>'number','label'=>'GMQ cible ovin','unit'=>'g/j','display_order'=>22],
            // Ruminants — Caprins
            ['group'=>'elevage','key'=>'cycle_caprin_lait','value'=>'210','type'=>'number','label'=>'Durée lactation caprine','unit'=>'jours','display_order'=>23],
            ['group'=>'elevage','key'=>'lait_cible_chevre','value'=>'1.5','type'=>'number','label'=>'Production lait cible / chèvre / jour','unit'=>'L/j','display_order'=>24],
            ['group'=>'elevage','key'=>'gmq_cible_caprin','value'=>'100','type'=>'number','label'=>'GMQ cible caprin','unit'=>'g/j','display_order'=>25],
            // Tabaski
            ['group'=>'elevage','key'=>'tabaski_target_weight','value'=>'35','type'=>'number','label'=>'Poids cible vente Tabaski','unit'=>'kg','display_order'=>27],
            // Pisciculture
            ['group'=>'pisciculture','key'=>'cycle_tilapia','value'=>'180','type'=>'number','label'=>'Durée grossissement tilapia','unit'=>'jours','display_order'=>1],
            ['group'=>'pisciculture','key'=>'cycle_carpe','value'=>'180','type'=>'number','label'=>'Durée grossissement carpe','unit'=>'jours','display_order'=>2],
            ['group'=>'pisciculture','key'=>'ph_min','value'=>'6.5','type'=>'number','label'=>'pH minimal acceptable','display_order'=>3],
            ['group'=>'pisciculture','key'=>'ph_max','value'=>'8.5','type'=>'number','label'=>'pH maximal acceptable','display_order'=>4],
            ['group'=>'pisciculture','key'=>'o2_alert','value'=>'4','type'=>'number','label'=>'Seuil alerte O₂ dissous','unit'=>'mg/L','display_order'=>5],
            ['group'=>'pisciculture','key'=>'ammonia_alert','value'=>'0.02','type'=>'number','label'=>'Seuil alerte NH₃','unit'=>'mg/L','display_order'=>6],
            ['group'=>'pisciculture','key'=>'temp_min','value'=>'25','type'=>'number','label'=>'Température eau minimale','unit'=>'°C','display_order'=>7],
            ['group'=>'pisciculture','key'=>'temp_max','value'=>'32','type'=>'number','label'=>'Température eau maximale','unit'=>'°C','display_order'=>8],
            ['group'=>'pisciculture','key'=>'taux_survie_cible','value'=>'85','type'=>'number','label'=>'Taux de survie cible','unit'=>'%','display_order'=>9],
            ['group'=>'pisciculture','key'=>'fc_cible','value'=>'1.5','type'=>'number','label'=>'IC cible pisciculture','unit'=>'kg/kg','display_order'=>10],
            // Autres volailles
            ['group'=>'elevage','key'=>'cycle_dinde_chair','value'=>'120','type'=>'number','label'=>'Durée cycle dinde chair','unit'=>'jours','display_order'=>30],
            ['group'=>'elevage','key'=>'cycle_caille_ponte','value'=>'240','type'=>'number','label'=>'Durée cycle caille ponte','unit'=>'jours','display_order'=>31],
            ['group'=>'elevage','key'=>'cycle_caille_chair','value'=>'42','type'=>'number','label'=>'Durée cycle caille chair','unit'=>'jours','display_order'=>32],
        ];

        foreach ($settings as $s) {
            DB::table('settings')->updateOrInsert(
                ['group' => $s['group'], 'key' => $s['key'], 'farm_id' => null],
                array_merge($s, ['is_sensitive' => false, 'farm_id' => null, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    public function down(): void {
        DB::table('settings')->whereNull('farm_id')->where(function ($q) {
            $q->where('group', 'pisciculture')
              ->orWhereIn('key', [
                  'cycle_ovin_engraissement','cycle_ovin_reproducteur','gmq_cible_ovin',
                  'cycle_caprin_lait','lait_cible_chevre','gmq_cible_caprin',
                  'tabaski_target_weight',
                  'cycle_dinde_chair','cycle_caille_ponte','cycle_caille_chair',
              ]);
        })->delete();
    }
};
