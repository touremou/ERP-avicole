<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DURÉE D'INCUBATION AU RÉFÉRENTIEL DES ESPÈCES.
 *
 * Le nombre existait déjà — mais en TROIS endroits qui pouvaient se contredire :
 *
 *   1. un tableau PHP codé en dur dans IncubationController::index() (6 espèces) ;
 *   2. le repli « 21 » de StartIncubation, qui ignorait ce tableau : une mise en
 *      couvoir sans durée explicite datait l'éclosion d'un canard à 21 jours au
 *      lieu de 28 — une semaine d'écart sur le mirage et le retournement ;
 *   3. le réglage `couvoir.incubation_days`, lu seulement par la barre de
 *      progression de la liste.
 *
 * Conséquences concrètes : la ferme ne pouvait rien corriger (un canard de
 * Barbarie incube 35 jours, pas 28), et TOUTE espèce ajoutée par l'utilisateur
 * — l'application le permet — retombait silencieusement sur la poule.
 *
 * La durée vit désormais sur l'espèce, modifiable, et les trois chemins la
 * lisent au même endroit.
 */
return new class extends Migration
{
    /** Durées usuelles (jours), reprises du tableau qui vivait dans le contrôleur. */
    private const BY_SLUG = [
        'poulet'  => 21,
        'pintade' => 28,
        'dinde'   => 28,
        'canard'  => 28,
        'caille'  => 17,
        'pigeon'  => 18,
    ];

    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->unsignedSmallInteger('incubation_days')->nullable()->after('tracks_eggs');
        });

        foreach (self::BY_SLUG as $slug => $days) {
            DB::table('species')->where('slug', $slug)->update(['incubation_days' => $days]);
        }
    }

    public function down(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->dropColumn('incubation_days');
        });
    }
};
