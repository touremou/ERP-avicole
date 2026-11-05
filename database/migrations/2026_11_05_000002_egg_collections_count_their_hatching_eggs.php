<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ŒUFS INCUBABLES : CALCULÉS À CHAQUE COLLECTE, AVEC UN AJUSTEMENT.
 *
 * Décision de l'exploitation : incubables = collectés − cassés − anormaux −
 * autres déclassés (EggProduction::incubables). La colonne `incubable_eggs`
 * existait mais aucune saisie ne l'écrivait : elle valait 0 partout.
 *
 *   • `downgraded_eggs` : l'ajustement facultatif, œufs écartés de la
 *     couvaison sans être cassés ni anormaux (deux jaunes, hors poids,
 *     déformés…) ;
 *   • les collectes déjà en base reçoivent leurs incubables, par la même
 *     formule (sans ajustement : il n'existait pas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('egg_productions', function (Blueprint $table) {
            $table->unsignedInteger('downgraded_eggs')->default(0)->after('small_eggs');
        });

        DB::table('egg_productions')->update([
            'incubable_eggs' => DB::raw(
                'CASE WHEN total_eggs_collected - COALESCE(broken_eggs, 0) - COALESCE(small_eggs, 0) > 0 '
                . 'THEN total_eggs_collected - COALESCE(broken_eggs, 0) - COALESCE(small_eggs, 0) ELSE 0 END'
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('egg_productions', function (Blueprint $table) {
            $table->dropColumn('downgraded_eggs');
        });

        DB::table('egg_productions')->update(['incubable_eggs' => 0]);
    }
};
