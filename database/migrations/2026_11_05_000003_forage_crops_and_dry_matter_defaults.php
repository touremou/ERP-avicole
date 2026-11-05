<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AUTONOMIE FOURRAGÈRE : LES RÉCOLTES À VENIR ET LES TENEURS PAR DÉFAUT.
 *
 * Décisions de l'exploitation :
 *   • un cycle de culture peut être « culture fourragère / auto-consommation »,
 *     avec sa date de récolte prévue (colonne existante) et son rendement
 *     estimé en kg de MATIÈRE SÈCHE : il entre dans la projection
 *     d'autonomie du troupeau, et l'alerte prévient d'une rupture avant lui ;
 *   • un aliment sans teneur en MS renseignée prend celle de sa NATURE, au
 *     réglage de la ferme (il comptait à 100 %) : ensilage 33 %,
 *     enrubannage 50 %, foin 85 %, paille et concentrés 88 %.
 */
return new class extends Migration
{
    private const TENEURS = [
        'ensilage'    => ['33', 'ensilages'],
        'enrubannage' => ['50', 'enrubannages'],
        'foin'        => ['85', 'foins'],
        'paille'      => ['88', 'pailles'],
        'concentre'   => ['88', 'concentrés et provendes'],
    ];

    public function up(): void
    {
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->boolean('is_forage')->default(false);
            $table->decimal('expected_dm_yield_kg', 14, 2)->nullable();
        });

        $ordre = 63;
        foreach (self::TENEURS as $nature => [$valeur, $libelle]) {
            if (DB::table('settings')->where('group', 'stocks')->where('key', "dm_default_{$nature}")->exists()) {
                continue;
            }
            DB::table('settings')->insert([
                'group' => 'stocks', 'key' => "dm_default_{$nature}", 'value' => $valeur,
                'type' => 'number', 'label' => "Teneur en matière sèche par défaut des {$libelle}",
                'unit' => '%', 'display_order' => $ordre++,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Setting::clearCache();
    }

    public function down(): void
    {
        Schema::table('crop_cycles', function (Blueprint $table) {
            $table->dropColumn(['is_forage', 'expected_dm_yield_kg']);
        });

        DB::table('settings')->where('group', 'stocks')
            ->whereIn('key', array_map(fn ($n) => "dm_default_{$n}", array_keys(self::TENEURS)))->delete();
        Setting::clearCache();
    }
};
