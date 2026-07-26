<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1 — Santé terrain : DÉLAI D'ATTENTE (withdrawal period) d'une intervention
 * sanitaire. C'est le cœur réglementaire : après un vaccin ou un traitement,
 * la viande (et les œufs) ne sont pas consommables avant l'expiration du délai
 * fixé par la notice du produit. Sans ce champ, rien n'empêchait d'abattre un
 * lot traité la veille.
 * Nullable : les interventions historiques et celles sans délai (vitamines,
 * désinfection de bâtiment vide) restent valides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_checks', function (Blueprint $table) {
            $table->unsignedSmallInteger('withdrawal_days')->nullable()->after('mode_administration');
        });
    }

    public function down(): void
    {
        Schema::table('health_checks', function (Blueprint $table) {
            $table->dropColumn('withdrawal_days');
        });
    }
};
