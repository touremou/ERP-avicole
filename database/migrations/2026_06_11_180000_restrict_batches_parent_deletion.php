<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * batches.building_id / provider_id / employee_id étaient en onDelete('cascade') :
 * une suppression DÉFINITIVE (forceDelete) d'un bâtiment, fournisseur ou employé
 * effaçait tout l'historique de production rattaché. En usage normal le risque
 * est limité (ces entités sont en SoftDeletes), mais on passe la contrainte en
 * RESTRICT pour empêcher toute perte d'historique au niveau base.
 *
 * SQLite ne sait pas modifier une contrainte FK sans reconstruire la table :
 * on n'applique donc ce changement que sur MySQL/MariaDB (cible de production).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['building_id']);
            $table->dropForeign(['provider_id']);
            $table->dropForeign(['employee_id']);

            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->foreign('provider_id')->references('id')->on('providers')->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['building_id']);
            $table->dropForeign(['provider_id']);
            $table->dropForeign(['employee_id']);

            $table->foreign('building_id')->references('id')->on('buildings')->cascadeOnDelete();
            $table->foreign('provider_id')->references('id')->on('providers')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }
};
