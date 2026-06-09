<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('mill_productions', function (Blueprint $table) {
        // 1. Ajout de la colonne après operator_id
        // On la met en 'nullable' pour ne pas bloquer les anciennes productions
        $table->unsignedBigInteger('supervisor_id')->nullable()->after('operator_id');

        // 2. Création de la contrainte d'intégrité
        $table->foreign('supervisor_id')
              ->references('id')
              ->on('employees')
              ->onDelete('set null'); // Si un employé est supprimé, la production reste
    });
}

public function down()
{
    Schema::table('mill_productions', function (Blueprint $table) {
        $table->dropForeign(['supervisor_id']);
        $table->dropColumn('supervisor_id');
    });
}
};
