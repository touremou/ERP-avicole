<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * L'ACTION PRISE À LA DÉCLARATION D'UN INCIDENT SANITAIRE.
 *
 * Décision de l'exploitation : un incident se déclare avec sa date, ses
 * symptômes, son lot, sa gravité ET l'action prise sur le moment (isolement,
 * traitement administré, délai d'attente à respecter…). Le diagnostic et la
 * prescription du vétérinaire viennent ensuite, dans leurs propres champs.
 *
 * Colonne nullable : les incidents déjà déclarés n'en portaient pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('health_incidents', 'action_taken')) {
            Schema::table('health_incidents', function (Blueprint $table) {
                $table->text('action_taken')->nullable()->after('symptoms');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('health_incidents', 'action_taken')) {
            Schema::table('health_incidents', function (Blueprint $table) {
                $table->dropColumn('action_taken');
            });
        }
    }
};
