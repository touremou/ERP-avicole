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
        Schema::table('batches', function (Blueprint $table) {
            // On change le type de la colonne pour inclure toutes les options
            $table->enum('type', ['poussiniere', 'chair', 'ponte', 'reproducteur'])->change();
        });
    }

    public function down()
    {
        Schema::table('batches', function (Blueprint $table) {
            // Revenir à un simple string si besoin
            $table->string('type')->change();
        });
    }
};
