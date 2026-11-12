<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DURÉE DE CYCLE PROPRE À LA SOUCHE — réglable par l'administrateur.
 *
 * Le stade d'un lot (Démarrage → Croissance → Finition / Ponte) se déduit de
 * son âge et de la durée de son cycle (Batch::current_phase). Cette durée
 * venait des seules courbes de référence ou du type de production : un mouton
 * Djallonké et un croisé d'embouche n'avaient pas de réglage à eux.
 * Vide = durée du type de production, comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('animal_breeds', 'cycle_days')) {
            Schema::table('animal_breeds', function (Blueprint $table) {
                $table->unsignedSmallInteger('cycle_days')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('animal_breeds', 'cycle_days')) {
            Schema::table('animal_breeds', fn (Blueprint $table) => $table->dropColumn('cycle_days'));
        }
    }
};
