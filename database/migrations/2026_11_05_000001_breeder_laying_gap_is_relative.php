<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ALERTE DE PONTE DU RÉFÉRENTIEL REPRODUCTEURS : 5 % RELATIFS, PLUS 5 POINTS.
 *
 * Décision de l'exploitation : le seuil suit le stade du lot (norme × 0,95).
 * 5 points absolus sur une ponte de 20 % valaient une chute de 25 %. Le
 * réglage change de sens, il change donc de clé : l'ancienne valeur (des
 * points) ne se lit pas comme un pourcentage.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('group', 'elevage')->where('key', 'breeder_laying_gap_points')->delete();

        if (! DB::table('settings')->where('group', 'elevage')->where('key', 'breeder_laying_gap_pct')->exists()) {
            DB::table('settings')->insert([
                'group' => 'elevage', 'key' => 'breeder_laying_gap_pct', 'value' => '5',
                'type' => 'number',
                'label' => 'Alerte de ponte HD sous la courbe du référentiel reproducteurs (% de la norme)',
                'unit' => '%', 'display_order' => 90,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'elevage')->where('key', 'breeder_laying_gap_pct')->delete();

        if (! DB::table('settings')->where('group', 'elevage')->where('key', 'breeder_laying_gap_points')->exists()) {
            DB::table('settings')->insert([
                'group' => 'elevage', 'key' => 'breeder_laying_gap_points', 'value' => '5',
                'type' => 'number', 'label' => 'Alerte de ponte HD sous la courbe du référentiel reproducteurs',
                'unit' => 'points', 'display_order' => 90,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Setting::clearCache();
    }
};
