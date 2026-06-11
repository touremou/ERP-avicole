<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supprime la colonne `responsible` (chaîne) des lots.
 *
 * Cette colonne dupliquait l'information déjà portée par `employee_id`
 * (+ relation employee()). Elle était écrite à la création mais jamais
 * relue (aucune vue ne l'affiche), ce qui créait un risque de
 * désynchronisation si l'employé responsable changeait de nom.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('batches', 'responsible')) {
            Schema::table('batches', function (Blueprint $table) {
                $table->dropColumn('responsible');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('batches', 'responsible')) {
            Schema::table('batches', function (Blueprint $table) {
                $table->string('responsible')->nullable();
            });
        }
    }
};
